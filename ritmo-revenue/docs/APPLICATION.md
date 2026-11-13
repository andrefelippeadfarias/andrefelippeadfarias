# Ritmo Revenue: application and WordPress layer

The use cases (`src/Application`), the wiring for WordPress (`src/WordPress`, `src/Plugin.php`), the admin screens (`src/Admin`, `templates/`, `assets/`) and the REST API (`src/Rest`). The engine they call is in [ENGINE.md](ENGINE.md); the repositories, lock and HTTP clients in [INFRASTRUCTURE.md](INFRASTRUCTURE.md); the connectors in [CONNECTORS.md](CONNECTORS.md). Part 2 is generated from the code.

## Part 1. Guide

### 1.1 The run pipeline

Every run of a property goes through the same pipeline (`ShadowRun\RunPipeline`), one short stage at a time; each completed stage is stored with the run (`RunRepository::advance()`: stage name, summary, state for the next stage) in one transaction with what the stage stored, so a run interrupted by the end of a request resumes from its next stage. Before every stage the pipeline checks the lock (`busy` when another request took it) and the emergency switch: a run that may write stops, and so does the shadow run a person starts from the dashboard; a scheduled run in shadow mode (the property's mode, or forced by the switch) goes on reading and watching (operations spec O9, D7). The stages after the write (`AfterWriteStage`: verify and report) run even under the switch, so what already went out is confirmed and recorded. A stage that throws ends the run as `failed` with `Stage "<name>": <message>` (the plugin's own exceptions carry sentences safe to show; others are named by their class only).

The mode is fixed when the run starts (`runs.mode`): the property's mode, or shadow under the emergency switch; the kind is `shadow` (the dashboard's "Run shadow now"), `full`, `light`, `manual` ("Run now") or `approved_write`. "Today" is `Clock::today($property->timezone)` when the run opens; the run decides `plan.horizon_nights` nights from today (60 with the neutral rules) with the rules version in force when it started. A run of an earlier day is never continued: it is closed as failed and its plan is never written.

| Stage | Reads | Stores | Summary |
|---|---|---|---|
| `collect` | `listListings()`, `readOverrides()`, `readPrices()` (only with the calendar read, ARCHITECTURE.md section 6), `readInventory(..., withPrices: true)`, `readBookingsModifiedSince()` | snapshot of the PMS calendar, bookings | listings found or missing, overrides, pricing nights, inventory nights, bookings read |
| `watch` | the bookings moved since the cursor (`MovementTracker`, one list for the movement and the watch), the snapshot, the stored bookings, the listing values read against the stored ones (`ListingWatch`), the journal rows of earlier writes read back (`JournalWatch` over `ResumeService`, in every mode), the earlier observations of every night | alerts (security, below expected, overbooked, closed without booking, manual change, listing changed, groups left open), the cancellation-wave hold, the listing values stored when they changed, the record of writes a dead request left applied (change log, step ledger), the observations and the freezes of nights someone else changed; state: nights closed in the PMS without a booking, the groups left open and, in a light run, the filter of the nights it may touch (`LightScope`) | bookings read, rows settled, listing changes, groups open, movements, findings, wave hold, manual changes, nights frozen |
| `measure` | the snapshot of this run, the stored bookings | alerts `overbooked`, `sold_mismatch`, `push_disabled`, `push_stale` | occupancy per window and room, overbooked nights, mismatches, push state |
| `decide` | `RunInputsReader`: the snapshot, the date rules and the pricing calendar, the market anchors and the market ruler built from them (`AnchorRuler`), the step ledger, the owner orders with the freezes, the blocked nights and the light filter of the watch, the listing values at the start of the week (`WeeklyReference`) | nothing | room nights by `NightLine` code, items, deletions |
| `validate` | the validation the assembly made | nothing | items `would_write`, `no_change`, `rejected`, issue codes, refusal |
| `shadow` | the plan of `decide` and `validate` | `plan_items` (one row per field of an item and per deletion, with its status and details) and `run_payloads` | rows stored, `would_write`, slices, `writes_sent` = 0 |
| `write` | the plan built again in this request (`PlanRebuild`: a validated plan lives only in the process that validated it) | by mode: nothing (shadow); a request waiting for approval and the alert `approval_pending` (approval); the write (autonomous, or the approved write) | mode, entries proposed or held, writes sent, steps by status, stop reason, refusal of the gate |
| `verify` | the journal rows of this run read back as stored (`JournalNights`); the push times and PMS prices of the steps waiting for the channel (`DeliveryService`) | change log, step ledger and observations of what was stored (`RecordService`); steps delivered | nights confirmed, changes and steps recorded, delivery |
| `report` | the summaries of the run | nothing | the report lines (sync now first, security, overbooking, where every item went; three lines for a light run without anything to do) |

The write (`WriteStage`, `WriteRunner`) happens once per run: a request that finds journal rows of its own run (its process died while writing) ends the run as failed (`WriteInterrupted`) instead of sending again from the same reads; the next run's watch reads those rows back. A group left open between its deletion and its rewrite (found by the watch) is repaired first and alone (`BrokenGroup::repairItems`, through the validator and the gate): at once when autonomous; in approval mode its repair is the plan proposed, alone, and written once a person approves it (an approved write that does not cover the repair is still written). The rows of an open group stay unresolved, so every run sees it and raises its alert again until the rewrite is in force. Else the plan, without the price cuts a cancellation wave holds (`CancellationHold`, validated again), goes to the write gate with the authorization of the mode: the property's autonomous authorization (`ModeService::autonomousAuthorization`, from the last switch to autonomous) or the approval of a person (`HumanApproval` over the fresh plan cut down to the approved entries by `ApprovedSubset` and validated again). The gate issues a token or refuses (`WriteRefused`: the refusal is in the summary, nothing is sent); the executor sends the steps in series and stops at the first failure; `WriteReport` counts the steps and raises `write_failed` when the write failed or left groups open. Listings not recalculated on the property's today are recalculated first when the quota allows (`WriteRunner::staleListings`, `RecalcRoomQuota`); the "same run" of the quota is the latest scheduled routine (`QuotaRunStart`): a recalculation that failed is not tried again by a run started meanwhile. The executor gets a deadline from the request's time limit (`WordPress\TimeLimit`, which asks PHP for the write's time first). After an autonomous write `ModeService::afterWrite()` counts the runs in a row whose writes failed and lowers the property to approval after `modes.demote_after_failed_writes`.

`ShadowRunService::run($propertyId, $budgetSeconds = 20): RunOutcome` runs the `shadow` kind (always in shadow mode) under the property's single run lock (`LockedRunLauncher::lockKey`, `property_run:<id>`); with the emergency switch engaged it starts nothing and closes a run left open as `stopped` (`blocked`). `ShadowRunService::resumeOpenRuns()` resumes the open shadow runs. The scheduled runs go through `LockedRunLauncher` and `PipelineRunExecutor` (1.2).

The emergency switch (`EmergencySwitch`) is the option `ritmo_kill_switch` (Diagnostics and Operations screens, REST `POST /emergency`, both through `Mode\EmergencyControl`, which also ends every approval not written yet when the switch is engaged and records the event only when the switch moved) or the constant `RITMO_KILL_SWITCH` in wp-config.php, which the admin cannot release. The option is read with `KeyValueStore::getFresh()`, past WordPress's cache of the request, so a run sees between two stages (and the executor before every slice) a switch engaged by another request.

### 1.2 Wiring

`WordPress\Services::instance()` builds the objects of a request on first use: `WpdbDatabase`, `SystemClock`, `OptionsStore`, `DbLogger` with `LogSanitizer`, `SodiumVault::forSite()`, the migrator (through `dbDelta`), the emergency switch, the write gate (`gate()`, with the write and plan limits of the rules in force), `ConnectorFactory` (with the gate as write authority) and `Run\RunStack`. `RunStack` is the run machinery wired once over a database, a clock, a store, a logger, the connectors, the switch, the gate, a capability check (`WpCapabilityCheck`: `user_can`), a mailer and the recipients of the alerts: the repositories, the alerts (`AlertService`, `AlertNotifier`), the pipeline and its stages, `RunOpener` (opens and reopens runs), `PipelineRunExecutor` (the `RunExecutor` of the scheduler), `LockedRunLauncher`, `RunNowService`, `ShadowRunService`, `SchedulerTick` (with `CurrentSchedule`, the schedule of the rules in force, and `ScheduleHousekeeping`), `StallMonitor`, `ApprovalDesk`, `ModeService`, `UndoService` and `UndoWriter`. The end-to-end tests build the same stack over SQLite and the simulator.

`WordPress\ConnectorFactory` implements `Application\ConnectorSource`. It builds `Beds24Connector` (refresh or long-life token from the vault entry `beds24_token`, room map and units from the room types, property ids from the setting `beds24_property_ids`, `CreditAwareHttpClient`) and `PriceLabsConnector` (key `pricelabs_api_key`, PMS name `pricelabs_pms`, `ThrottledHttpClient` with 60/min and 1000/h, `DbQuotaLedger`), both over `GuardedHttpClient(new WpHttpClient())` with the read routes, the write routes of both systems (exact paths at their base URLs) and the gate as write authority. A missing secret is `ConnectionNotConfigured`. The base URLs are production unless `RITMO_DEV` is true and `RITMO_BEDS24_BASE_URL` / `RITMO_PRICELABS_BASE_URL` are defined. `readsPricingCalendar()` is true unless the site defines `RITMO_PRICING_CALENDAR_READ` as `false`. `pricingPmsName()` is the PMS name the payload carries. `test($provider)` never throws.

Lifecycle: `Activator::activate()` (`Scheduler::schedule()` first: the five-minute WP-Cron event `ritmo_tick`, the event of earlier versions cleared; then migrations, vault, role and capabilities, and the neutral rules when none, or the stored rules completed with the settings added since, `RuleSetRepository::upgradeCurrent()`; rules that do not pass are logged and leave the rest done), `Deactivator::deactivate()` and `Uninstaller` (`Scheduler::unschedule()`; the uninstaller drops tables, `ritmo_*` options, role and capabilities only when the setting `delete_data_on_uninstall` is true). `Plugin::boot()` registers the text domain, the upgrade check (on `init`, every kind of request, one request at a time under a lock: the tick and the REST API never run over an old database; the tick does nothing while the tables are not up to date), the menu, the assets, the admin actions, the REST routes, the interval `ritmo_five_minutes` (filter `cron_schedules`), the tick (`Plugin::tick()` → `SchedulerTick::run()`; a failure is logged by its kind and raises `scheduler_stalled`, never thrown into WP-Cron) and, on the admin screens, the tick's event put back when it is missing and `StallMonitor::check()` (`scheduler_stalled` when slots wait with no tick; guarded the same way). After an approval `Scheduler::tickNow()` schedules a one-off tick and spawns WP-Cron, so the approved write starts at once (operations spec D16), still under the property's run lock.

### 1.3 Dashboard and setup

- `OccupancyQuery::forProperty($id)`: occupancy of each goal window, per room and for the property, from the latest stored PMS calendar (no external call), with the traffic light of the 7-night window (`OccupancySignal`).
- `PropertyDashboard::build($id)`: the property, the occupancy, the open alerts, the last shadow run, the last completed one, the push state it measured, "what would be written", "refused" and the issues of the plan as a whole.
- `SetupService`: `createProperty()` (always in shadow mode), `saveRoomType()`, `importPreset()` (properties forced to shadow mode), `importSamples()` (`SampleCsv`).

### 1.4 Modes, approvals and undo

- `Mode\ModeService::change()`: shadow → approval by a person who manages the connections when `Mode\ModePreconditions::toApproval()` is met (the last `modes.min_shadow_runs` runs completed in shadow with a plan, the newest within 24 h; both connections tested OK within 24 h; no open `connector_down` or `write_failed`; the switch released); approval → autonomous only when `modes.autonomous_enabled`, by a person who manages the connections, typing the property's id, after `modes.min_approval_days` and `modes.min_written_plans`, with no `write_failed` in 7 days and no wave holding cuts (`toAutonomous()`); shadow → autonomous never; any lower mode by whoever approves, manages the rules or the connections, at once (the write in progress stops before its next slice: its heartbeat checks the mode). `ModeRefused` names why, with each unmet condition coded (`Admin\RefusalText` translates them). Every change is an event of the change log (`OperationEventRepository`); any change ends the requests waiting and lapses the approvals not written yet.
- `Approval\ApprovalService` (the requests, `PlanEntries`, `PartialApproval`, `ApprovedSubset`, `ApprovalWindow`, `ApprovalGrant`, `ApprovalKind`, `ApprovalStatus`, `ApprovalSelection`, `RestrictedPlan`, `ApprovalRefused`, `PlanEntry`) and `ApprovalDesk` (what the screen and the REST API call): approve all or whole groups, refuse; the decision is recorded and an approval puts an `approved_write` slot on the schedule. At most one approval of a kind waits for its write: a new approval or a new proposal lapses the older one, and the approved write takes the approval decided last. `CapabilityCheck` is the port of `user_can`. `WaveHoldService` observes and releases the hold of a cancellation wave.
- Undo (`UndoWriter`, over `UndoService` and `UndoRequest`): the undo is a plan read from the change log rows and the nights as they are now (a night changed since is a conflict and is left alone), validated, approved by the person who asked (approval capability), issued a token by the gate for exactly that plan and sent by the executor, as a run of kind `undo` under the property's run lock; the nights read back as stored are recorded and the rows marked undone.
- Confirmation (`Verify`): `Verifier`, `NightConfirmation`, `StoredStatus`, `RecordService`, `RecordOutcome`, `DeliveryService`, `ConfirmationQuota`, `ThirdPartyWatch`, `ThirdPartyResult` (VERIFY.md).

### 1.5 Screens and capabilities

The admin is one menu "Ritmo Revenue" of twelve entries (spec 05, section 2; `Admin\Components\Menu`), registered by `Admin\AdminScreens` from each area's screens: Overview, Nights, Approvals, Alerts, To do, Changes, Reports, Market, Orders, Rules, Operations, Settings (tabs: connections, setup wizard, notifications, diagnostics, uninstall). The M1-M3 slugs redirect to the new screens keeping the property. Every screen checks its capability (403 by URL), prints only escaped values and works without JavaScript (post, redirect, get); scripts only improve it.

| Capability | Who has it by default | What it allows |
|---|---|---|
| `ritmo_view` | administrator, Revenue Manager, Revenue Viewer | every screen, exports, own alert e-mail choice |
| `ritmo_manage_rules` | administrator, Revenue Manager | rules, orders, market imports, setup, "Run now" |
| `ritmo_approve` | administrator, Revenue Manager | approvals, undo, releasing frozen nights, engaging the emergency switch |
| `ritmo_manage_connections` | administrator, Revenue Manager | connections, modes, releasing the emergency switch, uninstall choice |
| `ritmo_manage_safety` | administrator only (D2; a site may grant it by hand) | safety fields of the rules, listing minimum/base/maximum in an approval, the switch to autonomous; always with a typed confirmation |

`WordPress\Roles` creates the role `ritmo_revenue_manager` (the four first capabilities) and the read-only role `ritmo_revenue_viewer`, gives the five to administrators, and applies versioned grants once (`roles_version`); the uninstaller removes both roles and the five capabilities. Every form posts to admin-post.php through `Admin\Screens\ScreenActions`: POST only, then the capability, then the nonce (per object where the action names one), and only then the handler reads its fields; it redirects back with a notice (`Admin\Notice`). A source test fails when a template echoes an unescaped value or reads `$_GET`/`$_POST`.

| Area | Classes |
|---|---|
| UI kit | `Admin\Components\...`: `Menu`, `Tab`, `Screen`, `FormAction`, `Capability`, `Input`, `Money`, `Percent`, `Ratio`, `PriceText`, `NightDate`, `Labels`, `Paging`, `ConfirmTyped` (typed confirmation: the property id, the new value or the provider's name), `DomainText` (the label and help of a rules setting, translated), `CsvWriter` (formula guard on every text cell; UTF-8 with BOM), `PrivacyGuard` (refuses e-mails, phones and documents in free text), `ErrorText`, `UserFacing`; `View` renders templates; `Notice` carries the message across the redirect; `ReasonText` and `RefusalText` say the why of a number and of a refusal in words. |
| Central screens | `Admin\Screens\...`: `Screens` (renderers, counters, actions of the area), `Page`, `ScreenServices`, `OverviewScreen`, `NightsScreen`, `NightDetailView`, `NightText`, `NightActions`, `ApprovalsScreen`, `ApprovalActions`, `PmsChangeText` (a PMS change in words), `WarningText`, `AlertsScreen`, `AlertText`, `AlertActions`, `TodoScreen`, `TodoText`, `TodoActions`, `ChangesScreen`, `ChangeActions`, `UndoNow`, `UndoText`, `OperationsScreen`, `OperationsText`, `OperationsActions`, `SettingsScreen`, `SettingsActions`, `ConnectionsView`, `ConnectionText`, `ConnectionActions`, `DiagnosticsView`, `DiagnosticsActions`, `WizardView`, `WizardActions`, `PeopleNames`. Application side: `Dashboard\...` (`StatusQuery`, `StatusStrip`, `Overview\OverviewQuery`, `Alerts\AlertList`, `Alerts\AlertFilter`, `Changes\ChangeList`, `Changes\ChangeFilter`, `Changes\ChangeGroups`, `Changes\UndoRequests`, `Connections\ConnectionTests` (one test per provider every 30 s), `Connections\ConnectionReason`, `Diagnostics\SupportBundle` (JSON with a whitelist of keys), `Setup\SetupWizard`, `Setup\SetupState`, `Setup\WizardProgress`, `Setup\ListingSuggestions`, `Setup\RoomRowsRefused`, `Setup\SafetyValues`, `Setup\WouldDo`, `Todo\TodoGenerator`, `Todo\TodoItem`, `Todo\TodoService`, `Todo\TodoStore`) and `Grid\...` (`NightGrid`, `NightGridQuery`, `NightCell`, `NightMarks`, `NightFilter`, `NightDetail`, `NightDetailQuery`, `GridContext`, `LastPlan`, `PlanIndex`, `PlannedNight`; approvals: `Approvals\ApprovalBoard`, `RequestView`, `RowBuilder`, `EntryRow`, `EntryWarnings`, `Segment`, `Segmenter`, `Impact`, `RelationHints`). See docs/ADMIN.md. |
| Editors | `Admin\Editors\...`: `EditorScreens`, `EditorPage`, `EditorServices`, `RulesScreen`, `RulesActions`, `RulesLayout` (every schema field placed once), `Placement`, `FieldView`, `FieldWidget`, `FieldContext`, `FieldPath`, `ValueText`, `ChoiceLabels`, `EditorText`, `RuleFormReader`, `RequestFields`, `FormScope`, `FormErrors`, `FormResult`, `DraftDesk`, `UserMetaDraftStorage`, `ReviewView`, `VersionsView`, `PresetFile`, `RelationsView`, `RelationForm`, `RelationText`, `RelationCycle`, `RelationActions`, `CalendarView`, `CalendarActions`, `OrdersScreen`, `OrdersActions`, `OrderText`. Application side, `Rules\...`: `RuleSetEditor` (draft per user, three-way merge, version N+1, never a write), `RuleDraft`, `RuleDraftStorage`, `RuleDiff`, `FieldChange`, `RuleMerge`, `RuleChecks`, `RulesRefused`, `SafetyFields`, `SavedVersion`, `VersionHistory`, `PendingApprovals`, `ApprovalServiceAdapter`, `PrivacyCheck`, `Percent`, `Calendar\HolidayCatalogue`, `Calendar\HolidayList`, `Calendar\HolidayProposals`, `Calendar\HolidayProposal`, `Calendar\Easter`, `Orders\OrderDesk`, `Orders\OrderBoard`, `Orders\OrderForm`. See docs/EDITORS.md. |
| Market | `Admin\Market\...`: `MarketScreen`, `MarketActions`, `MarketServices`, `MarketText`, `CsvUploadReader` (the upload read in place with fgetcsv, never moved or kept), `TransientPreviewStore`. Application side, `Market\...`: `MarketImportService`, `ImportPreview`, `PreviewStore`, `UploadedFile`, `MarketRefused`, `MarketBoard`, `MarketExport`, `CompetitorService`, `CompetitorInput`, `MarketSeriesService` (series rebuilt when their inputs changed), `MarketRulerAssembler` (the ruler of a run), `MarketFreshness`, `MarketReminder`, `MarketSettings`. See docs/MARKET.md. |
| Reports | `Admin\Reports\...`: `ReportsScreen`, `ReportsActions`, `ReportsServices`, `ReportsText`, `ReportViews`, `ReportTable`, `Cell`, `BarChart` (inline SVG, the table is the accessible source), `OccupancyTables`, `MonthTables`, `ReviewTables`, `WeeklyTables`, `RunLineText` (the run report translated for the reader), `WriteReasonText`. Application side, `Report\...`: `ReportService`, `ReportScope`, `ReportSettings`, `Store\SnapshotStore`, `Store\RevenueTargetStore`, `Store\TargetRow`. See docs/REPORTS.md. |

### 1.6 REST API `ritmo/v1`

Every route needs a logged-in session, the `wp_rest` nonce in `X-WP-Nonce` (401 otherwise) and a capability (403 otherwise); the use cases check again what each person may do.

| Route | Capability | Answer |
|---|---|---|
| `GET /status` | `ritmo_view` | version, database version, emergency switch, calendar read, properties with their mode and last run |
| `GET /properties/{id}/occupancy` | `ritmo_view` | `OccupancyQuery::forProperty()`; 404 for an unknown property |
| `POST /properties/{id}/shadow-run` | `ritmo_manage_rules` | `RunOutcome`; 409 when busy, 423 under the emergency switch |
| `GET /runs/{id}` | `ritmo_view` | the run, its summary, room nights by item status |
| `POST /properties/{id}/run` | `ritmo_manage_rules` | "Run now" (`RunNowService`): status, run id; 409 when busy |
| `POST /properties/{id}/mode` | `ritmo_view` | `mode`, `confirmation`, `reason`; 403 or 409 with the reason of `ModeRefused` |
| `GET /properties/{id}/approvals` | `ritmo_view` | the requests waiting, with their entries and groups |
| `POST /approvals/{id}` | `ritmo_approve` | `property`, `decision` (exactly `approve` or `reject`), optional `keys` (a non-empty list of entry keys); 400 for anything else (`Rest\OperationInput`: a refusal typed wrong never approves), 409 when no longer pending |
| `GET /properties/{id}/changes` | `ritmo_view` | the latest change log rows |
| `POST /properties/{id}/undo` | `ritmo_approve` | `changes` (ids): the undo's status, nights written back and changes undone; 409 busy, 423 blocked |
| `POST /emergency` | `ritmo_view` at the route, then `Roles::maySwitchEmergency()` | `engaged` (required boolean): engages or releases the switch (recorded when it moved); 400 without a clear value (a malformed request never releases it), 403 for a person who may not |

| `POST /emergency` (engage) | `ritmo_approve` or `ritmo_manage_connections` | releasing needs `ritmo_manage_connections` (D23) |

The first four are `Rest\RestRoutes`; the operation routes are `Rest\OperationRoutes`. The routes where a page waits for an answer (D31) are `Rest\Screens\ScreenRoutes`/`ScreenEndpoints` (approval status, the preview of a selection, connection test, alerts, runs), `Rest\Editors\EditorRoutes`/`EditorEndpoints` (rule-set versions and their comparison), `Rest\Market\MarketRoutes`/`MarketEndpoints` (the seven market routes of MARKET.md) and `Rest\Reports\ReportRoutes`/`ReportEndpoints` (the reports, the review snapshot, the targets, a run's report). Every one goes through `RestRoutes::allowed()` (a real capability check and the REST nonce); errors are translated sentences with 400, 403, 404 or 409, never internal data.

### 1.7 The throwaway site and the end-to-end test

```
bin/e2e.sh [screenshot folder]
```

1. `bin/wp-test-site.sh start` downloads WordPress and the SQLite integration plugin into `.wp-test/downloads`, unpacks a fresh site, writes a test-only wp-config.php (SQLite, `WP_HTTP_BLOCK_EXTERNAL`, `RITMO_DEV`, the simulator's base URLs), links this folder as the plugin, installs and activates it, then serves the site on 127.0.0.1:8090 and the simulator on 127.0.0.1:8091 (scenario moved to start today; writes enabled in the simulator's world).
2. `tests/e2e/seed.php` imports `presets/default.json` (with `modes.min_shadow_runs` 0 for the test), creates the invented property with three room types and pastes competitor prices.
3. `tests/e2e/shadow-run.js` (Playwright, Chromium): log in; connections; setup; shadow run from the dashboard; REST checks; Diagnostics; the simulator's log: only reads. Then the operations: switch to approval on the Operations screen, "Run now" (the plan waits; still only reads), approve everything, run the due tick through `wp-cron.php`: the approved writes reach the simulator; engage the emergency switch on the screen: "Run now" sends no write.
4. Second pass with `RITMO_PRICING_CALENDAR_READ=1`: a second shadow run; only reads and `POST /pricelabs/v1/listing_prices` (and the writes of the approval of the first pass).
5. WordPress' debug log must hold no PHP error, warning, notice or deprecation from a plugin file.

The PHP end-to-end tests of the run (`tests/Integration/Run`, no browser) cover: shadow sends no write; approval writes nothing before the approval and the approved (or partly approved) entries after; autonomous writes in slices and records; a crash after N requests and the resume without sending twice; a process killed while writing and continued later (the run ends as failed, nothing is sent from its reads, the manual change made meanwhile is kept); a 429 in the middle; a failed recalculation not tried again before the next routine; the emergency switch between slices and between a deletion and its rewrite (repaired first by the next run; in approval mode proposed first and written once approved); a manual change on a night written by a group (frozen, never overwritten) and the plugin's own merged writes (never taken for manual changes); a listing base changed in the pricing system (the run writes over the base it read, stores it and alerts); approvals that end with a lower mode, the emergency switch or a newer approval; undo; a light run with a new booking (only its nights get a step) and without movement (three lines); the security and below-expected alerts.

### 1.8 Where each class is

| Class | Role |
|---|---|
| `RunPipeline`, `Stage`, `AfterWriteStage`, `StageResult`, `RunContext`, `RunOutcome` | The pipeline (1.1). |
| `CollectStage`, `MeasureStage`, `DecideStage`, `ValidateStage`, `ShadowStage` | The reading and deciding stages. |
| `WatchStage`, `JournalWatch`, `ListingWatch`, `LightScope`, `BookingsUnwatched`, `RecalcRoomQuota`, `QuotaRunStart` | The watch before deciding, the write journal read back, the listing values read against the stored ones, the scope of a light run, the failed booking read, the recalculation quota by room and the start of its "same run". |
| `WriteStage`, `PlanRebuild`, `RebuiltPlan`, `WriteRunner`, `WriteReport`, `WriteInterrupted` | The write stage; a write re-entered after its process died. |
| `StayWriteStep`, `StayAssembly`, `PmsState`, `PushedFields`, `Write\Calendar\StayWriteRound`, `PmsWriteBatch`, `PmsCalendarChanges`, `UndoPmsEntries` | The stay writes of a full run (STAY.md, "Writes"): PMS changes planned, checked against the pricing items the validator accepted, sealed with the plan and converted by the gate into calendar steps; what the push sets, for the third-party watch (D6); the PMS side of an undo. |
| `VerifyStage`, `JournalNights`, `ReportStage`, `RunEndChecks` | Confirmation, record and report; the checks at the end of a full run (market age and gaps, steps overdue at the channel, the 12-month review, the PMS cap). |
| `RunInputsReader`, `AssemblyState`, `WeeklyReference`, `StateCodec`, `PlanItemStatus`, `PlanRefused` | What the plan assembly reads; the plan as arrays; state between stages. |
| `RunStack`, `RunOpener`, `PipelineRunExecutor`, `CurrentSchedule` | Composition, opening runs, the executor of the scheduler, the schedule in force. |
| `ShadowRunService`, `RunNowService`, `LockedRunLauncher`, `RunLauncher`, `RunExecutor`, `LaunchResult`, `LaunchStatus`, `FailureKind` | Starting and continuing runs under the property's lock. |
| `SchedulerTick`, `TickReport`, `ScheduleSource`, `ScheduleHousekeeping`, `StallMonitor`, `PauseService`, `MovementTracker`, `MovementRead`, `LightRoundService`, `LightRoundRequest`, `RoomQuota` | The scheduler and the light run (OPERATIONS.md). |
| `AlertService`, `AlertNotifier`, `MailText`, `WatchService`, `WatchInput`, `WatchOutcome` | Alerts (ALERTS.md). |
| `ModeService`, `ModePreconditions`, `ModeRefused`, `EmergencyControl` | Modes and the emergency switch (1.1, 1.4). |
| `UndoWriter` | Undo (1.4). |
| `Write\...` (`WriteGate`, `WriteContext`, `WriteAuthorization`, `AutonomousAuthorization`, `HumanApproval`, `IssuedToken`, `TokenGrant`, `PlanHash`, `TonightGuard`, `TonightState`, `FieldLocks`, `WriteRefused`, `WriteExecutor`, `WriteRun`, `WriteOutcome`, `StepSender`, `StepResult`, `StopReason`, `RunProgress`, `PmsGuard`, `StoredCheck`, `WriteJournal`, `StateReader`, `StoredState`, `NightRule`, `OpStatus`, `RecalcGuard`, `RecalcQuota`, `RecalcAttempt`, `AttemptLog`, `AttemptOutcome`, `AttemptRefused`, `QuotaVerdict`, `RollingWindow`, `ResumeService`, `ResumePlanner`, `ResumeReport`, `BrokenGroup`, `NightClass`, `WriteSchedule`, `WriteStep`, `CalendarChange`) | The write path (WRITE.md). |
| `Approval\...`, `Verify\...` | Approvals and confirmation (1.4, VERIFY.md). |
| `ConnectorSource`, `ConnectionNotConfigured`, `EmergencySwitch` | Where use cases get connectors; missing credentials; the general switch. |
| `OccupancyQuery`, `OccupancySignal`, `SetupService`, `SampleCsv` | Occupancy for the overview and the REST route, setup (1.3). |
| `Services`, `ConnectorFactory`, `WpCapabilityCheck`, `Activator`, `Deactivator`, `Uninstaller`, `Roles`, `Scheduler`, `TimeLimit` | WordPress wiring and lifecycle (1.2). |
| `AdminScreens`, `View`, `Notice`, `ReasonText`, `RefusalText` and the areas of 1.5 | Screens and form actions (1.5); the refusals through the text domain. |
| `RestRoutes`, `OperationRoutes`, `OperationInput` | The REST API (1.6); the strict reading of decisions and of the switch. |

### 1.9 Not in this milestone

Licence and the setup assistant's AI help come in milestone 5. Left open in M4 (each listed in docs/OPERATIONS.md, section "Open points"): the relation re-check and audit passes of the run (D1), the channel limits read back from the PMS (D3), one bookings read per run (D4), the stay writes of a light run, the undo as an approval request (D9), the PMS room list in the wizard (D4 of spec 05).

## Part 2. Signature reference

Generated from the code. Do not edit between the markers; run `php bin/sync-generated.php`.

<!-- BEGIN GENERATED SIGNATURES (php bin/sync-generated.php) -->

#### `Ritmo\Application\Alerts\AlertNotifier` (final class)

```php
public function __construct(AlertLedger $ledger, Mailer $mailer, RecipientDirectory $recipients, NotificationPolicy $policy, Clock $clock, Logger $logger);
public function notify(AlertRecord $alert, RaiseAction $action): Delivery;
public function sendDigest(string $propertyId): int;
```

#### `Ritmo\Application\Alerts\AlertService` (final class)

```php
public function __construct(AlertLedger $ledger, AlertNotifier $notifier, Clock $clock);
public function raise(AlertDraft $draft, ?int $runId = null): ?AlertRecord;
public function raiseAll(array $drafts, ?int $runId = null): array;
public function settle(string $propertyId, array $codes, array $raisedKeys, ?Closure $inScope = null): int;
public function resolveKey(string $dedupeKey): bool;
public function acknowledge(int $alertId, int $userId, bool $isApprover): void;
public function resolve(int $alertId, int $userId, bool $isApprover): void;
```

#### `Ritmo\Application\Alerts\MailText` (final class)

```php
public static function subject(AlertRecord $alert): string;
public static function body(AlertRecord $alert): string;
public static function digestSubject(string $propertyId, int $count): string;
public static function digestBody(array $alerts): string;
```

#### `Ritmo\Application\Alerts\WatchInput` (final class)

```php
public readonly string $propertyId;
public readonly array $bookings;
public readonly DateTimeImmutable $since;
public readonly array $inventory;
public readonly array $bookedUnits;
public readonly array $roomUnits;
public readonly DateRange $checked;
public readonly ?int $runId;
public function __construct(string $propertyId, array $bookings, DateTimeImmutable $since, array $inventory, array $bookedUnits, array $roomUnits, DateRange $checked, ?int $runId = null);
```

#### `Ritmo\Application\Alerts\WatchOutcome` (final class)

```php
public readonly array $security;
public readonly array $belowExpected;
public readonly array $overbooked;
public readonly array $closed;
public readonly array $blocked;
public readonly array $waves;
public readonly array $originsSeen;
public function __construct(array $security, array $belowExpected, array $overbooked, array $closed, array $blocked, array $waves, array $originsSeen);
```

#### `Ritmo\Application\Alerts\WatchService` (final class)

```php
public function __construct(AlertService $alerts, AlertsConfig $config, CancellationWave $waves, InventorySnapshotRepository $snapshots);
public function watch(WatchInput $in): WatchOutcome;
public function audit(string $propertyId, array $audits, array $rooms, ?int $runId = null): void;
```

#### `Ritmo\Application\Approval\ApprovalDesk` (final class)

```php
public function __construct(PlanApprovalRepository $requests, CapabilityCheck $capabilities, ScheduledRunRepository $slots, OperationEventRepository $events, PropertyRepository $properties, Clock $clock, ?Closure $wake = null);
public function approve(string $propertyId, int $requestId, int $userId, ?array $selected, ApprovalConfig $config, string $note = ''): ApprovalGrant;
public function reject(string $propertyId, int $requestId, int $userId, ApprovalConfig $config, string $note = ''): void;
public function pending(string $propertyId, ApprovalConfig $config): array;
```

#### `Ritmo\Application\Approval\ApprovalGrant` (final class)

```php
public readonly int $approvalId;
public readonly string $propertyId;
public readonly ApprovalKind $kind;
public readonly string $planHash;
public readonly DateTimeImmutable $planDay;
public readonly int $approvedBy;
public readonly string $capability;
public readonly DateTimeImmutable $approvedAt;
public readonly DateTimeImmutable $expiresAt;
public readonly array $approvedEntries;
public function __construct(int $approvalId, string $propertyId, ApprovalKind $kind, string $planHash, DateTimeImmutable $planDay, int $approvedBy, string $capability, DateTimeImmutable $approvedAt, DateTimeImmutable $expiresAt, array $approvedEntries);
public function approvedKeys(): array;
public function listingChanges(): array;
```

#### `Ritmo\Application\Approval\ApprovalKind` (enum)

```php
case Plan = 'plan';
case Listing = 'listing';
case Undo = 'undo';
```

#### `Ritmo\Application\Approval\ApprovalRefused` (final class extends RuntimeException)

```php
const NOT_FOUND = 'not_found';
const NOT_PENDING = 'not_pending';
const NOT_APPROVED = 'not_approved';
const NO_CAPABILITY = 'no_capability';
const EXPIRED = 'approval_expired';
const NOTHING_SELECTED = 'nothing_selected';
const UNKNOWN_ENTRY = 'unknown_entry';
const LISTING_NEEDS_EXPLICIT_APPROVAL = 'listing_needs_explicit_approval';
const UNSEALED_PLAN = 'unsealed_plan';
public readonly string $reason;
public function __construct(string $reason, string $message);
```

#### `Ritmo\Application\Approval\ApprovalSelection` (final class)

```php
public readonly array $approved;
public readonly array $rejected;
public readonly array $added;
public function __construct(array $approved, array $rejected, array $added);
```

#### `Ritmo\Application\Approval\ApprovalService` (final class)

```php
const SUPERSEDED = 'superseded';
const DAY_OR_CALENDAR = 'day_turned_or_calendar_old';
public function __construct(PlanApprovalRepository $repository, CapabilityCheck $capabilities, Clock $clock, ApprovalConfig $config = \Ritmo\Domain\Write\ApprovalConfig::__set_state(array(
   'maxCalendarAgeHours' => 6,
)));
public function propose(ValidatedPlan $plan, ?int $runId, DateTimeImmutable $calendarReadAt, ?int $rulesetVersion = null, ApprovalKind $kind = ApprovalKind::Plan): array;
public function approve(int $id, int $userId, ?array $selected = null, string $note = ''): ApprovalGrant;
public function reject(int $id, int $userId, string $note = ''): void;
public function grant(int $id): ApprovalGrant;
public function entriesOf(int $id): array;
public function markWritten(int $id, int $writeRunId): bool;
public function expireDue(string $propertyId): int;
public function expireAll(string $propertyId, string $reason): int;
```

#### `Ritmo\Application\Approval\ApprovalStatus` (enum)

```php
case Pending = 'pending';
case Approved = 'approved';
case Rejected = 'rejected';
case Expired = 'expired';
case Superseded = 'superseded';
case Written = 'written';
case Lapsed = 'lapsed';
```

#### `Ritmo\Application\Approval\ApprovalWindow` (final class)

```php
public static function expiresAt(DateTimeImmutable $planDay, string $timezone, DateTimeImmutable $calendarReadAt, int $maxCalendarAgeHours): DateTimeImmutable;
```

#### `Ritmo\Application\Approval\ApprovedSubset` (final class)

```php
public static function restrict(ApprovalGrant $grant, Plan $fresh, array $bases): RestrictedPlan;
```

#### `Ritmo\Application\Approval\CapabilityCheck` (interface)

```php
const APPROVE = 'ritmo_approve';
public function userCan(int $userId, string $capability): bool;
```

#### `Ritmo\Application\Approval\PartialApproval` (final class)

```php
public static function close(array $entries, array $selected): ApprovalSelection;
```

#### `Ritmo\Application\Approval\PlanEntries` (final class)

```php
const BEFORE = 'before:';
public static function of(Plan $plan, array $bases): array;
public static function hash(array $entries): string;
public static function pms(PmsChangeEntry $change): PlanEntry;
```

#### `Ritmo\Application\Approval\PlanEntry` (final class)

```php
const ITEM = 'item';
const DELETION = 'deletion';
const LISTING = 'listing';
const PMS = 'pms';
public readonly string $kind;
public readonly string $roomCode;
public readonly string $subject;
public readonly array $fields;
public readonly ?string $orderId;
public function __construct(string $kind, string $roomCode, string $subject, array $fields = [], ?string $orderId = null);
public function key(): string;
public function group(): string;
public function sameAs(self $other): bool;
public function toArray(): array;
public static function fromArray(array $data): self;
```

#### `Ritmo\Application\Approval\RestrictedPlan` (final class)

```php
public readonly Plan $plan;
public readonly array $needsNewApproval;
public readonly array $notApproved;
public function __construct(Plan $plan, array $needsNewApproval, array $notApproved);
```

#### `Ritmo\Application\Approval\WaveHoldService` (final class)

```php
public function __construct(WaveHoldRepository $holds, CapabilityCheck $capabilities, Clock $clock, WaveConfig $config = \Ritmo\Domain\Write\Hold\WaveConfig::__set_state(array(
   'minCancellations' => 3,
   'windowHours' => 3,
   'holdHours' => NULL,
   'scope' => 
  \Ritmo\Domain\Write\Hold\HoldScope::Property,
)));
public function observe(string $propertyId, array $bookings, DateTimeImmutable $since): ?WaveHold;
public function active(string $propertyId): ?WaveHold;
public function release(string $propertyId, int $userId): bool;
```

#### `Ritmo\Application\ConnectionNotConfigured` (final class extends RuntimeException)

```php
public static function forProvider(string $label): self;
```

#### `Ritmo\Application\ConnectorSource` (interface)

```php
public function pms(): PmsConnector;
public function pricing(): PricingConnector;
public function readsPricingCalendar(): bool;
public function pricingPmsName(): string;
public function pricingStaySync(): StaySyncCapability;
```

#### `Ritmo\Application\Dashboard\Alerts\AlertFilter` (final class)

```php
const STATUSES = ['unresolved', 'open', 'acknowledged', 'resolved', 'all'];
const SEVERITIES = ['critical', 'warning', 'info'];
const GROUPS = [['security_unknown_origin', 'security_cancellation_burst', 'security_zero_value', 'security_zero_value_modified'], ['booking_below_expected', 'overbooked', 'sold_mismatch'], ['inventory_closed_without_booking', 'inventory_stay_off_rule', 'inventory_arrival_off_rule', 'push_disabled', 'push_stale'], ['write_failed', 'recalc_quota_exhausted', 'manual_change_detected', 'listing_changed_manually'], ['connector_down'], ['run_late', 'run_missed', 'scheduler_stalled', 'vigilance_gap'], ['approval_pending', 'approval_expired', 'mode_demoted']];
public readonly string $propertyId;
public readonly string $status;
public readonly string $severity;
public readonly string $group;
public readonly ?DateTimeImmutable $from;
public readonly ?DateTimeImmutable $to;
public function __construct(string $propertyId = '', string $status = 'unresolved', string $severity = '', string $group = '', ?DateTimeImmutable $from = null, ?DateTimeImmutable $to = null);
public function codes(): array;
public function query(): array;
```

#### `Ritmo\Application\Dashboard\Alerts\AlertList` (interface)

```php
public function count(AlertFilter $filter): int;
public function page(AlertFilter $filter, int $offset, int $limit): array;
public function find(int $id): ?array;
public function countBySeverity(?string $propertyId, array $severities): int;
```

#### `Ritmo\Application\Dashboard\Changes\ChangeFilter` (final class)

```php
const SYSTEMS = ['', 'pricing', 'pms', 'listing'];
const WHO = ['', 'autonomous', 'person', 'undo'];
public readonly string $propertyId;
public readonly string $room;
public readonly ?DateTimeImmutable $nightFrom;
public readonly ?DateTimeImmutable $nightTo;
public readonly ?DateTimeImmutable $changedFrom;
public readonly ?DateTimeImmutable $changedTo;
public readonly string $system;
public readonly string $who;
public readonly bool $notUndone;
public function __construct(string $propertyId, string $room = '', ?DateTimeImmutable $nightFrom = null, ?DateTimeImmutable $nightTo = null, ?DateTimeImmutable $changedFrom = null, ?DateTimeImmutable $changedTo = null, string $system = '', string $who = '', bool $notUndone = false);
public function query(): array;
```

#### `Ritmo\Application\Dashboard\Changes\ChangeGroups` (final class)

```php
public static function byRun(array $rows): array;
public static function userId(string $actor): ?int;
```

#### `Ritmo\Application\Dashboard\Changes\ChangeList` (interface)

```php
public function count(ChangeFilter $filter): int;
public function page(ChangeFilter $filter, int $offset, int $limit): array;
```

#### `Ritmo\Application\Dashboard\Changes\UndoRequests` (interface)

```php
public function request(string $propertyId, array $changeIds, int $userId): array;
```

#### `Ritmo\Application\Dashboard\Connections\ConnectionReason` (final class)

```php
const OK = 'ok';
const AUTH = 'auth';
const FORBIDDEN = 'forbidden';
const NETWORK = 'network';
const RATE_LIMITED = 'rate_limited';
const NOT_FOUND = 'not_found';
const NO_PROPERTIES = 'no_properties';
const NO_LISTINGS = 'no_listings';
const PMS_MISMATCH = 'pms_mismatch';
const NOT_CONFIGURED = 'not_configured';
const UNKNOWN = 'unknown';
const ALL = ['ok', 'auth', 'forbidden', 'network', 'rate_limited', 'not_found', 'no_properties', 'no_listings', 'pms_mismatch', 'not_configured', 'unknown'];
public static function of(ConnectionStatus $status): string;
public static function counts(ConnectionStatus $status): array;
```

#### `Ritmo\Application\Dashboard\Connections\ConnectionTests` (final class)

```php
const PREFIX = 'connection_test_';
const COOLDOWN_SECONDS = 30;
public function __construct(KeyValueStore $settings, Clock $clock);
public function last(string $provider): ?array;
public function withinCooldown(string $provider): ?array;
public function record(string $provider, ConnectionStatus $status, int $userId): array;
```

#### `Ritmo\Application\Dashboard\Diagnostics\SupportBundle` (final class)

```php
const KEYS = ['generated_at', 'versions', 'environment', 'active_plugins', 'settings', 'rules', 'properties', 'room_types', 'schedule', 'runs', 'alerts', 'log', 'migrations'];
public static function build(array $facts): array;
public static function settings(array $settings): array;
```

#### `Ritmo\Application\Dashboard\OccupancyQuery` (final class)

```php
const SIGNAL_WINDOW = 7;
public function __construct(PropertyRepository $properties, RoomTypeRepository $roomTypes, RuleSetRepository $ruleSets, InventorySnapshotRepository $snapshots, Clock $clock);
public function forProperty(string $propertyId): array;
```

#### `Ritmo\Application\Dashboard\OccupancySignal` (final class)

```php
const GREEN = 'green';
const AMBER = 'amber';
const RED = 'red';
const UNKNOWN = 'unknown';
const AMBER_MARGIN_POINTS = 10.0;
public static function classify(?float $percent, float $goal): string;
```

#### `Ritmo\Application\Dashboard\Overview\OverviewQuery` (final class)

```php
const SOON_NIGHTS = 4;
const PACE_DAYS = 60;
public function __construct(PropertyRepository $properties, RoomTypeRepository $roomTypes, RuleSetRepository $ruleSets, InventorySnapshotRepository $snapshots, BookingRepository $bookings, OccupancyQuery $occupancy, LastPlan $lastPlan, StatusQuery $status, AlertList $alerts, TodoStore $todos, Clock $clock);
public function forSite(): array;
public function soon(Property $property): array;
public function windows(Property $property): array;
public function pace(Property $property): array;
public function needsYou(Property $property): array;
public function lastRun(Property $property): array;
public function nextRuns(Property $property): array;
public function property(string $propertyId): Property;
```

#### `Ritmo\Application\Dashboard\Overview\Revenue` (final class)

```php
public static function onTheBooks(array $bookings, array $rooms, DateRange $window): array;
```

#### `Ritmo\Application\Dashboard\Setup\ListingSuggestions` (final class)

```php
const KEY = 'wizard_listings';
const MAX = 50;
public function __construct(KeyValueStore $settings);
public function remember(array $listings): void;
public function rows(array $taken = []): array;
public function currency(): ?string;
```

#### `Ritmo\Application\Dashboard\Setup\RoomRowsRefused` (final class extends InvalidArgumentException)

```php
public readonly array $errors;
public function __construct(array $errors);
```

#### `Ritmo\Application\Dashboard\Setup\SafetyValues` (final class)

```php
const RULE_KEYS = ['floors.fraction_of_reference_min', 'safety.weekly_listing_change_max', 'write.listing_changes_enabled', 'modes.autonomous_enabled'];
public static function fingerprint(RuleSet $rules, array $rooms): string;
public static function apply(RuleSet $rules, array $changes): RuleSet;
```

#### `Ritmo\Application\Dashboard\Setup\SetupState` (final class)

```php
const KEY = 'setup';
public function __construct(KeyValueStore $settings);
public function rulesChosen(): bool;
public function safetyFingerprint(): ?string;
public function markRulesChosen(DateTimeImmutable $at, int $userId): void;
public function confirmSafety(DateTimeImmutable $at, int $userId, ?int $version, string $fingerprint): void;
```

#### `Ritmo\Application\Dashboard\Setup\SetupWizard` (final class)

```php
const MANAGE_SAFETY = 'ritmo_manage_safety';
const NOTE_RULES = 'Setup wizard';
const NOTE_SAFETY = 'Setup wizard: safety limits confirmed';
public function __construct(Database $db, PropertyRepository $properties, RoomTypeRepository $roomTypes, RuleSetRepository $ruleSets, RunRepository $runs, SetupService $setup, ConnectionTests $tests, SetupState $state, CapabilityCheck $capabilities, Clock $clock);
public function progress(?string $propertyId): WizardProgress;
public function saveRooms(array $rows): int;
public function chooseRules(array $weekendDays, int $userId): int;
public function confirmSafety(string $propertyId, array $limits, array $referenceMins, int $userId): void;
public function rules(): RuleSet;
```

#### `Ritmo\Application\Dashboard\Setup\WizardProgress` (final class)

```php
const STEPS = ['welcome', 'pms', 'pricing', 'property', 'rooms', 'rules', 'safety', 'shadow_run', 'would_do'];
const CAPABILITY = ['view', 'connections', 'connections', 'rules', 'rules', 'rules', 'safety', 'rules', 'view'];
public function __construct(array $facts);
public function done(int $step): bool;
public function current(): int;
public function complete(): bool;
```

#### `Ritmo\Application\Dashboard\Setup\WouldDo` (final class)

```php
const NEAREST = 10;
public function __construct(LastPlan $lastPlan);
public function summary(string $propertyId, array $rooms = []): array;
```

#### `Ritmo\Application\Dashboard\StatusQuery` (final class)

```php
public function __construct(PropertyRepository $properties, EmergencySwitch $switch, OperationStateRepository $states, PlanApprovalRepository $requests, RunRepository $runs, Clock $clock);
public function forProperty(string $propertyId): StatusStrip;
public function waitingEverywhere(): int;
```

#### `Ritmo\Application\Dashboard\StatusStrip` (final class)

```php
public readonly string $propertyId;
public readonly string $mode;
public readonly bool $emergency;
public readonly bool $paused;
public readonly ?DateTimeImmutable $pausedUntil;
public readonly int $waitingRequests;
public readonly int $waitingEntries;
public readonly ?DateTimeImmutable $waitingExpires;
public readonly ?array $lastRun;
public function __construct(string $propertyId, string $mode, bool $emergency, bool $paused, ?DateTimeImmutable $pausedUntil, int $waitingRequests, int $waitingEntries, ?DateTimeImmutable $waitingExpires, ?array $lastRun);
```

#### `Ritmo\Application\Dashboard\Todo\TodoGenerator` (final class)

```php
const SYNC_NOW = 'sync_now';
const CONFIRM_ORIGINS = 'confirm_origins';
const SAFETY_CONFIRM = 'safety_confirm';
const CONNECTION_TEST = 'connection_test';
const SETUP_KEY = 'setup';
const TEST_PREFIX = 'connection_test_';
const PROVIDERS = ['beds24', 'pricelabs'];
public function __construct(TodoStore $store, KeyValueStore $settings, Clock $clock);
public function refresh(string $propertyId, string $timezone, RuleSet $rules, array $recentChanges): void;
```

#### `Ritmo\Application\Dashboard\Todo\TodoItem` (final class)

```php
const PLUGIN = 'plugin';
const PERSON = 'person';
const OPEN = 'open';
const DONE = 'done';
public readonly string $propertyId;
public readonly string $source;
public readonly string $kind;
public readonly string $title;
public readonly string $details;
public readonly ?DateTimeImmutable $dueOn;
public readonly string $roomCode;
public readonly ?DateTimeImmutable $night;
public readonly string $dedupeKey;
public readonly array $params;
public readonly int $createdBy;
public readonly string $status;
public readonly ?DateTimeImmutable $createdAt;
public readonly ?DateTimeImmutable $closedAt;
public readonly ?int $closedBy;
public readonly ?int $id;
public function __construct(string $propertyId, string $source, string $kind, string $title, string $details = '', ?DateTimeImmutable $dueOn = null, string $roomCode = '', ?DateTimeImmutable $night = null, string $dedupeKey = '', array $params = [], int $createdBy = 0, string $status = 'open', ?DateTimeImmutable $createdAt = null, ?DateTimeImmutable $closedAt = null, ?int $closedBy = null, ?int $id = null);
public function isOpen(): bool;
public function isDue(DateTimeImmutable $today): bool;
```

#### `Ritmo\Application\Dashboard\Todo\TodoService` (final class)

```php
const NOTE = 'note';
const MAX_TITLE = 190;
const MAX_DETAILS = 1000;
public function __construct(TodoStore $store, Clock $clock);
public function add(string $propertyId, string $title, string $details, ?DateTimeImmutable $dueOn, int $userId): int;
public function close(string $propertyId, int $id, int $userId): void;
public function reopen(string $propertyId, int $id, int $userId): void;
public function syncNow(string $propertyId, string $roomCode, DateTimeImmutable $today): int;
public function list(string $propertyId): array;
```

#### `Ritmo\Application\Dashboard\Todo\TodoStore` (interface)

```php
public function add(TodoItem $item, DateTimeImmutable $now, bool $once = false): int;
public function find(int $id): ?TodoItem;
public function open(?string $propertyId, int $limit = 200): array;
public function done(?string $propertyId, int $limit = 50): array;
public function setStatus(int $id, string $status, int $userId, DateTimeImmutable $now): bool;
public function closeKeys(array $keys, DateTimeImmutable $now): int;
public function openKeys(string $kind): array;
public function countDue(?string $propertyId, DateTimeImmutable $today): int;
```

#### `Ritmo\Application\EmergencySwitch` (final class)

```php
const KEY = 'kill_switch';
const CONSTANT = 'RITMO_KILL_SWITCH';
public function __construct(KeyValueStore $store, bool $engagedByConstant = false);
public static function constantIsSet(): bool;
public function isEngaged(): bool;
public function isEngagedByConstant(): bool;
public function engage(): void;
public function release(): void;
```

#### `Ritmo\Application\Grid\Approvals\ApprovalBoard` (final class)

```php
public function __construct(PropertyRepository $properties, RoomTypeRepository $roomTypes, RuleSetRepository $ruleSets, PlanApprovalRepository $requests, PlanItemRepository $planItems, WaveHoldRepository $holds, Clock $clock);
public function waiting(string $propertyId): array;
public function beingWritten(string $propertyId): array;
public function history(string $propertyId, int $page, int $perPage = 20): array;
public function find(string $propertyId, int $requestId): RequestView;
public function status(int $requestId): ?array;
public function selection(string $propertyId, int $requestId, array $keys): array;
```

#### `Ritmo\Application\Grid\Approvals\EntryRow` (final class)

```php
const UP = 'up';
const DOWN = 'down';
const BOUNDS = 'bounds';
const REWRITE = 'rewrite';
const LISTING = 'listing';
const PMS = 'pms';
public readonly string $key;
public readonly string $kind;
public readonly string $room;
public readonly ?DateTimeImmutable $night;
public readonly string $subject;
public readonly string $group;
public readonly array $fields;
public readonly ?string $orderId;
public readonly string $direction;
public readonly ?float $floorBefore;
public readonly ?float $floorAfter;
public readonly ?int $floorPercent;
public readonly ?float $ceilingBefore;
public readonly ?float $ceilingAfter;
public readonly ?int $ceilingPercent;
public readonly string $reason;
public readonly ?string $paceStatus;
public readonly array $trace;
public readonly string $special;
public readonly array $warnings;
public readonly bool $held;
public readonly bool $beforeFromListing;
public function __construct(string $key, string $kind, string $room, ?DateTimeImmutable $night, string $subject, string $group, array $fields, ?string $orderId, string $direction, ?float $floorBefore, ?float $floorAfter, ?int $floorPercent, ?float $ceilingBefore, ?float $ceilingAfter, ?int $ceilingPercent, string $reason, ?string $paceStatus, array $trace, string $special, array $warnings, bool $held, bool $beforeFromListing = false);
public function before(): ?float;
public function after(): ?float;
public function changePercent(): ?float;
```

#### `Ritmo\Application\Grid\Approvals\EntryWarnings` (final class)

```php
const TONIGHT = 'tonight';
const TOMORROW = 'tomorrow';
const HOLIDAY = 'holiday';
const GROUP = 'group';
const RELATION = 'relation';
const HELD = 'held';
const ORDER = 'order';
const ALL = ['tonight', 'tomorrow', 'holiday', 'group', 'relation', 'held', 'order'];
public static function of(?DateTimeImmutable $night, DateTimeImmutable $today, string $holiday, bool $inGroup, bool $held, ?string $orderId, bool $linked): array;
public static function special(?DateTimeImmutable $night, DateTimeImmutable $today, string $holiday): string;
```

#### `Ritmo\Application\Grid\Approvals\Impact` (final class)

```php
public readonly int $nights;
public readonly int $up;
public readonly ?float $medianUp;
public readonly int $down;
public readonly ?float $medianDown;
public readonly ?array $largestRise;
public readonly int $soon;
public readonly int $holidays;
public readonly int $rewrites;
public readonly int $listing;
public static function of(array $rows): self;
```

#### `Ritmo\Application\Grid\Approvals\RelationHints` (final class)

```php
public function __construct(array $relations, array $roomTags, Closure $nightType, DateTimeImmutable $today);
public function pairs(array $entries): array;
public function split(array $entries, array $selected): array;
```

#### `Ritmo\Application\Grid\Approvals\RequestView` (final class)

```php
public readonly int $id;
public readonly string $kind;
public readonly string $status;
public readonly DateTimeImmutable $planDay;
public readonly DateTimeImmutable $createdAt;
public readonly DateTimeImmutable $expiresAt;
public readonly string $expiryReason;
public readonly bool $expired;
public readonly ?int $runId;
public readonly ?int $writeRunId;
public readonly ?int $decidedBy;
public readonly ?DateTimeImmutable $decidedAt;
public readonly string $note;
public readonly int $entries;
public readonly array $rooms;
public readonly array $listing;
public readonly Impact $impact;
public readonly array $pairs;
public readonly array $pms;
public function __construct(int $id, string $kind, string $status, DateTimeImmutable $planDay, DateTimeImmutable $createdAt, DateTimeImmutable $expiresAt, string $expiryReason, bool $expired, ?int $runId, ?int $writeRunId, ?int $decidedBy, ?DateTimeImmutable $decidedAt, string $note, int $entries, array $rooms, array $listing, Impact $impact, array $pairs, array $pms = []);
public function segmentCount(): int;
```

#### `Ritmo\Application\Grid\Approvals\RowBuilder` (final class)

```php
public function __construct(PlanIndex $plan, array $rooms, HolidayCalendar $calendar, DateTimeImmutable $today, ?WaveHold $hold);
public function rows(array $stored, array $linkedKeys = []): array;
```

#### `Ritmo\Application\Grid\Approvals\Segment` (final class)

```php
public readonly string $room;
public readonly array $entries;
public function __construct(string $room, array $entries);
public function first(): DateTimeImmutable;
public function last(): DateTimeImmutable;
public function count(): int;
public function nights(): int;
public function reason(): string;
public function direction(): string;
public function beforeRange(): ?array;
public function afterRange(): ?array;
public function medianChange(): ?float;
public function warnings(): array;
public function keys(): array;
public static function median(array $values): ?float;
```

#### `Ritmo\Application\Grid\Approvals\Segmenter` (final class)

```php
public static function segments(array $entries, array $roomOrder): array;
```

#### `Ritmo\Application\Grid\GridContext` (final class)

```php
public readonly HolidayCalendar $calendar;
public readonly array $holidays;
public readonly PlanIndex $plan;
public readonly ?array $run;
public readonly NightMarks $marks;
public readonly DateTimeImmutable $today;
public function __construct(HolidayCalendar $calendar, array $holidays, PlanIndex $plan, ?array $run, NightMarks $marks, DateTimeImmutable $today);
```

#### `Ritmo\Application\Grid\LastPlan` (final class)

```php
public function __construct(RunRepository $runs, PlanItemRepository $items);
public function of(array $recent): array;
public function ofRun(array $run): PlanIndex;
public function recentRuns(string $propertyId, int $limit = 20): array;
```

#### `Ritmo\Application\Grid\NightCell` (final class)

```php
public readonly string $roomCode;
public readonly DateTimeImmutable $night;
public readonly string $nightType;
public readonly string $period;
public readonly int $daysOut;
public readonly ?int $unitsSold;
public readonly int $unitsTotal;
public readonly bool $closed;
public readonly ?float $priceNow;
public readonly ?float $pmsPrice;
public readonly PlannedNight $planned;
public readonly array $trace;
public readonly bool $frozen;
public readonly ?DateTimeImmutable $frozenUntil;
public readonly array $orderIds;
public readonly int $alertCount;
public readonly ?DateTimeImmutable $lastWritten;
public readonly ?int $minStay;
public readonly string $restriction;
public function __construct(string $roomCode, DateTimeImmutable $night, string $nightType, string $period, int $daysOut, ?int $unitsSold, int $unitsTotal, bool $closed, ?float $priceNow, ?float $pmsPrice, PlannedNight $planned, array $trace, bool $frozen, ?DateTimeImmutable $frozenUntil, array $orderIds, int $alertCount, ?DateTimeImmutable $lastWritten, ?int $minStay, string $restriction);
public function soldOut(): bool;
public function closedWithoutBooking(): bool;
public function priced(): bool;
```

#### `Ritmo\Application\Grid\NightDetail` (final class)

```php
public readonly NightCell $cell;
public readonly RoomType $room;
public readonly array $bookings;
public readonly ?DetectedFreeze $freeze;
public readonly array $changes;
public readonly array $steps;
public readonly array $decisions;
public function __construct(NightCell $cell, RoomType $room, array $bookings, ?DetectedFreeze $freeze, array $changes, array $steps, array $decisions);
```

#### `Ritmo\Application\Grid\NightDetailQuery` (final class)

```php
public function __construct(NightGridQuery $grid, RoomTypeRepository $roomTypes, BookingRepository $bookings, DetectedFreezeRepository $freezes, ChangeLogRepository $changes, StepLedgerRepository $steps, PlanItemRepository $planItems);
public function detail(string $propertyId, string $roomCode, DateTimeImmutable $night): NightDetail;
```

#### `Ritmo\Application\Grid\NightFilter` (final class)

```php
const ALL = 'all';
const WOULD_CHANGE = 'would_change';
const REFUSED = 'refused';
const FROZEN = 'frozen';
const WITH_ORDER = 'with_order';
const WITH_ALERT = 'with_alert';
const STATUS_PREFIX = 'status:';
const SIMPLE = ['all', 'would_change', 'refused', 'frozen', 'with_order', 'with_alert'];
const STATUSES = ['ahead', 'on_pace', 'behind', 'very_behind', 'floor'];
public readonly string $only;
public static function from(string $only): self;
public static function values(): array;
public function accepts(NightCell $cell): bool;
```

#### `Ritmo\Application\Grid\NightGrid` (final class)

```php
public readonly Property $property;
public readonly array $rooms;
public readonly int $roomsInAll;
public readonly int $roomOffset;
public readonly array $nights;
public readonly array $cells;
public readonly array $holidays;
public readonly ?array $run;
public readonly NightFilter $filter;
public function __construct(Property $property, array $rooms, int $roomsInAll, int $roomOffset, array $nights, array $cells, array $holidays, ?array $run, NightFilter $filter);
public function cell(string $room, string $night): ?NightCell;
public function shown(): int;
public function first(): ?DateTimeImmutable;
public function last(): ?DateTimeImmutable;
```

#### `Ritmo\Application\Grid\NightGridQuery` (final class)

```php
const ROOMS_PER_PAGE = 12;
const MAX_NIGHTS = 90;
public function __construct(PropertyRepository $properties, RoomTypeRepository $roomTypes, RuleSetRepository $ruleSets, InventorySnapshotRepository $snapshots, BookingRepository $bookings, LastPlan $lastPlan, DetectedFreezeRepository $freezes, OrderRepository $orders, AlertRepository $alerts, ChangeLogRepository $changes, Clock $clock);
public function window(string $propertyId, DateTimeImmutable $from, int $nights, array $roomCodes, NightFilter $filter, int $roomPage = 1): NightGrid;
public function one(string $propertyId, string $roomCode, DateTimeImmutable $night): NightCell;
```

#### `Ritmo\Application\Grid\NightMarks` (final class)

```php
public function __construct(array $bookings, array $freezes, OrderBook $orders, array $alerts, array $changes, DateTimeImmutable $now);
public function sold(string $room, string $night): int;
public function freeze(string $room, string $night): ?DetectedFreeze;
public function orders(string $room, DateTimeImmutable $night): array;
public function orderBook(): OrderBook;
public function alerts(string $room, string $night): int;
public function lastWritten(string $room, string $night): ?DateTimeImmutable;
```

#### `Ritmo\Application\Grid\PlanIndex` (final class)

```php
public readonly ?int $runId;
public function __construct(iterable $items, ?int $runId = null);
public function withListing(array $rooms): self;
public static function empty(): self;
public function night(string $room, string $night): PlannedNight;
public function trace(string $room, string $night): array;
public function price(string $room, string $night): ?float;
public function all(): array;
public function isEmpty(): bool;
public static function key(string $room, string $night): string;
```

#### `Ritmo\Application\Grid\PlannedNight` (final class)

```php
const WOULD_WRITE = 'would_write';
const NO_CHANGE = 'no_change';
const REJECTED = 'rejected';
public readonly ?string $status;
public readonly string $reason;
public readonly ?string $paceStatus;
public readonly ?float $floorNow;
public readonly ?float $floorProposed;
public readonly ?int $floorPercent;
public readonly ?float $ceilingNow;
public readonly ?float $ceilingProposed;
public readonly ?int $ceilingPercent;
public readonly ?int $minStayNow;
public readonly ?int $minStayProposed;
public readonly array $issues;
public readonly array $relationCodes;
public readonly ?string $orderId;
public readonly bool $heldAtFloor;
public readonly bool $nowFromListing;
public function __construct(?string $status = null, string $reason = '', ?string $paceStatus = null, ?float $floorNow = null, ?float $floorProposed = null, ?int $floorPercent = null, ?float $ceilingNow = null, ?float $ceilingProposed = null, ?int $ceilingPercent = null, ?int $minStayNow = null, ?int $minStayProposed = null, array $issues = [], array $relationCodes = [], ?string $orderId = null, bool $heldAtFloor = false, bool $nowFromListing = false);
public function withListing(float $min, float $max): self;
public static function none(): self;
public function direction(): ?string;
public function paceCategory(): string;
```

#### `Ritmo\Application\Market\CompetitorInput` (final class)

```php
const NAME_MAX = 120;
const TEXT_MAX = 190;
public static function read(array $input): array;
public static function slug(string $name): string;
```

#### `Ritmo\Application\Market\CompetitorService` (final class)

```php
public function __construct(Database $db, Clock $clock, PropertyRepository $properties, CompetitorRepository $competitors, EditorEventRepository $events, MarketSeriesService $series);
public function save(string $propertyId, ?int $id, array $input, int $userId): int;
public function archive(string $propertyId, int $id, int $userId): void;
public function createMissing(string $propertyId, array $names, int $userId): int;
```

#### `Ritmo\Application\Market\ImportPreview` (final class)

```php
const LISTED_PROBLEMS = 200;
const LISTED_ROWS = 50;
public readonly string $token;
public readonly int $valid;
public readonly int $total;
public readonly int $invalidLines;
public readonly int $nights;
public readonly int $competitors;
public readonly ?DateTimeImmutable $firstCheckIn;
public readonly ?DateTimeImmutable $lastCheckIn;
public readonly array $firstRows;
public readonly array $problems;
public readonly int $moreProblems;
public readonly array $unknownCompetitors;
public function __construct(string $token, int $valid, int $total, int $invalidLines, int $nights, int $competitors, ?DateTimeImmutable $firstCheckIn, ?DateTimeImmutable $lastCheckIn, array $firstRows, array $problems, int $moreProblems, array $unknownCompetitors);
```

#### `Ritmo\Application\Market\MarketBoard` (final class)

```php
const PAGE_NIGHTS = 30;
const PAGE_PRICES = 100;
public function __construct(Clock $clock, CompetitorRepository $competitors, StaySampleRepository $samples, MarketSeriesRepository $series, MarketAnchorRepository $anchors, MarketImportRepository $imports);
public function nights(Property $property, RuleSet $rules, MarketConfig $config, DateTimeImmutable $from, int $count = 30): array;
public function competitors(string $propertyId): array;
public function prices(string $propertyId, array $filters, int $page): array;
public function imports(string $propertyId): array;
```

#### `Ritmo\Application\Market\MarketExport` (final class)

```php
const RULER_HEADER = ['night', 'night_type', 'series', 'percentile', 'value', 'currency', 'competitors', 'stays', 'origin', 'distance_days', 'newest_capture', 'oldest_capture'];
const SAMPLES_HEADER = ['competitor_code', 'check_in', 'check_out', 'room', 'price', 'price_basis', 'total', 'currency', 'captured_at', 'source', 'available'];
const COMPETITORS_HEADER = ['code', 'name', 'reference', 'room_label', 'tags', 'comparable_factor', 'factor_source', 'rating', 'active', 'own_listing'];
public function __construct(CompetitorRepository $competitors, StaySampleRepository $samples, MarketSeriesRepository $series, MarketAnchorRepository $anchors);
public function ruler(string $propertyId, string $currency, DateTimeImmutable $from, DateTimeImmutable $to, callable $typeOf): iterable;
public function competitors(string $propertyId): iterable;
public function samples(string $propertyId, array $filters): iterable;
```

#### `Ritmo\Application\Market\MarketFreshness` (final class)

```php
public function __construct(StaySampleRepository $samples);
public function check(string $propertyId, DateTimeImmutable $today, string $timezone, MarketConfig $config): array;
```

#### `Ritmo\Application\Market\MarketImportService` (final class)

```php
const PREVIEW_SECONDS = 1800;
const PREVIEW_MAX_BYTES = 1048576;
public function __construct(Database $db, Clock $clock, PropertyRepository $properties, RuleSetRepository $ruleSets, CompetitorRepository $competitors, StaySampleRepository $samples, MarketImportRepository $imports, EditorEventRepository $events, MarketSeriesService $series, PreviewStore $previews);
public function preview(string $propertyId, UploadedFile $file, int $userId): ImportPreview;
public function pending(string $propertyId, string $token, int $userId): ?ImportPreview;
public function cancel(string $propertyId, string $token, int $userId): void;
public function commit(string $propertyId, string $token, int $userId): array;
public function addOne(string $propertyId, array $input, int $userId): int;
```

#### `Ritmo\Application\Market\MarketRefused` (final class extends RuntimeException)

```php
const PROPERTY_UNKNOWN = 'property_unknown';
const COMPETITOR_UNKNOWN = 'competitor_unknown';
const REQUIRED = 'required';
const TOO_LONG = 'too_long';
const PRIVACY = 'privacy';
const BAD_CODE = 'bad_code';
const CODE_TAKEN = 'code_taken';
const BAD_TAG = 'bad_tag';
const BAD_FACTOR = 'bad_factor';
const BAD_RATING = 'bad_rating';
const ALREADY_IMPORTED = 'already_imported';
const FILE_ERROR = 'file_error';
const NOTHING_TO_IMPORT = 'nothing_to_import';
const PREVIEW_EXPIRED = 'preview_expired';
const PREVIEW_TOO_LARGE = 'preview_too_large';
const BAD_PRICE = 'bad_price';
public readonly string $reason;
public readonly string $field;
public readonly array $params;
public function __construct(string $reason, string $field = '', array $params = []);
```

#### `Ritmo\Application\Market\MarketReminder` (final class)

```php
const GAP_NIGHTS = 30;
public function __construct(MarketFreshness $freshness, MarketRulerAssembler $ruler);
public function check(Property $property, RuleSet $rules, MarketConfig $config, DateTimeImmutable $today): array;
```

#### `Ritmo\Application\Market\MarketRulerAssembler` (final class)

```php
public function __construct(MarketSeriesRepository $series, MarketAnchorRepository $anchors, LadderReferenceRepository $references);
public function of(Property $property, RuleSet $rules, DateTimeImmutable $from, DateTimeImmutable $to): MarketRuler;
```

#### `Ritmo\Application\Market\MarketSeriesService` (final class)

```php
const MAX_NIGHTS = 366;
public function __construct(Database $db, Clock $clock, PropertyRepository $properties, RuleSetRepository $ruleSets, CompetitorRepository $competitors, StaySampleRepository $samples, MarketSeriesRepository $series, MarketAnchorRepository $anchors);
public function rebuild(string $propertyId): array;
public function rebuildIfStale(string $propertyId): bool;
```

#### `Ritmo\Application\Market\MarketSettings` (final class)

```php
public static function of(?RuleSet $rules): MarketConfig;
```

#### `Ritmo\Application\Market\PreviewStore` (interface)

```php
public function put(string $token, array $payload, int $ttlSeconds): void;
public function get(string $token): ?array;
public function delete(string $token): void;
```

#### `Ritmo\Application\Market\UploadedFile` (final class)

```php
public readonly string $name;
public readonly string $sha256;
public readonly int $bytes;
public readonly array $records;
public function __construct(string $name, string $sha256, int $bytes, array $records);
```

#### `Ritmo\Application\Mode\EmergencyControl` (final class)

```php
const REASON = 'emergency_switch';
public function __construct(EmergencySwitch $switch, PropertyRepository $properties, ApprovalService $approvals, OperationEventRepository $events, Clock $clock);
public function set(bool $engage, string $via, string $actor): bool;
```

#### `Ritmo\Application\Mode\ModePreconditions` (final class)

```php
const CONNECTION_TEST_PREFIX = 'connection_test_';
const PROVIDERS = ['beds24', 'pricelabs'];
const SETUP_KEY = 'setup';
public function __construct(RunRepository $runs, AlertLedger $alerts, WaveHoldRepository $holds, EmergencySwitch $switch, KeyValueStore $store, Clock $clock);
public function toApproval(Property $property, ModesConfig $config): array;
public function toAutonomous(Property $property): array;
public static function unmet(string $code, array $params, string $text): array;
```

#### `Ritmo\Application\Mode\ModeRefused` (final class extends RuntimeException)

```php
const NO_CAPABILITY = 'no_capability';
const CONDITIONS = 'conditions_not_met';
const DISABLED = 'autonomous_disabled';
const CONFIRMATION = 'confirmation_mismatch';
public readonly string $reason;
public readonly array $conditions;
public function __construct(string $reason, string $message, array $conditions = []);
```

#### `Ritmo\Application\Mode\ModeService` (final class)

```php
const MANAGE_RULES = 'ritmo_manage_rules';
const MANAGE_CONNECTIONS = 'ritmo_manage_connections';
public function __construct(PropertyRepository $properties, OperationEventRepository $events, ApprovalService $approvals, CapabilityCheck $capabilities, AlertService $alerts, KeyValueStore $store, Clock $clock, ModePreconditions $preconditions);
public function change(string $propertyId, PropertyMode $to, int $userId, ModesConfig $config, string $confirmation = '', string $reason = ''): Property;
public function autonomousAuthorization(Property $property): ?AutonomousAuthorization;
public function afterWrite(Property $property, bool $failed, ModesConfig $config): bool;
```

#### `Ritmo\Application\Report\ReportScope` (final class)

```php
public readonly Property $property;
public readonly array $rooms;
public function __construct(Property $property, array $rooms);
public function capacities(): array;
public function roomProperty(): array;
```

#### `Ritmo\Application\Report\ReportService` (final class)

```php
public function __construct(BookingRepository $bookings, InventorySnapshotRepository $inventory, RevenueTargetStore $targets, SnapshotStore $snapshots, RuleSet $rules, ReportsConfig $config);
public function lines(ReportScope $scope, DateTimeImmutable $today): array;
public function occupancy(ReportScope $scope, DateTimeImmutable $today): array;
public function month(ReportScope $scope, string $month, DateTimeImmutable $asOf): MonthReport;
public function weekly(ReportScope $scope, DateTimeImmutable $asOf): WeeklyReport;
public function review(ReportScope $scope, DateTimeImmutable $asOf): ReviewResult;
public function reviewDue(ReportScope $scope, DateTimeImmutable $today): ReviewDue;
public function saveReview(ReportScope $scope, DateTimeImmutable $asOf, int $userId): ReviewSnapshot;
public function targets(ReportScope $scope, DateTimeImmutable $asOf): array;
```

#### `Ritmo\Application\Report\ReportSettings` (final class)

```php
public static function of(?RuleSet $rules): ReportsConfig;
```

#### `Ritmo\Application\Report\Store\RevenueTargetStore` (final class)

```php
const TABLE = 'revenue_targets';
public function __construct(Database $db);
public function forProperty(string $propertyId, array $months): array;
public function save(string $propertyId, TargetRow $row, int $userId, DateTimeImmutable $now): ?TargetRow;
```

#### `Ritmo\Application\Report\Store\SnapshotStore` (final class)

```php
const TABLE = 'report_snapshots';
const REVIEW = 'review_12m';
public function __construct(Database $db);
public function latestBefore(string $propertyId, DateTimeImmutable $day): ?ReviewSnapshot;
public function lastTaken(string $propertyId): ?DateTimeImmutable;
public function save(string $propertyId, ReviewSnapshot $snapshot, int $userId, DateTimeImmutable $now): void;
```

#### `Ritmo\Application\Report\Store\TargetRow` (final class)

```php
const FIELDS = ['minimum', 'realistic', 'market_occupancy_ly', 'market_adr_ly', 'occupancy_cap', 'adjust_floor', 'adjust_base', 'adjust_goal', 'market_sold_percent', 'market_last_year_percent'];
public readonly string $month;
public readonly array $values;
public function __construct(string $month, array $values);
public function value(string $field): ?float;
public function inputs(): TargetInputs;
public function target(): MonthTarget;
public function market(): ?MarketMonth;
```

#### `Ritmo\Application\Rules\ApprovalServiceAdapter` (final class implements PendingApprovals)

```php
public function __construct(ApprovalService $approvals, PlanApprovalRepository $requests);
public function waitingNights(string $propertyId): int;
public function expireAll(string $propertyId, string $reason): int;
```

#### `Ritmo\Application\Rules\Calendar\Easter` (final class)

```php
public static function sunday(int $year): DateTimeImmutable;
```

#### `Ritmo\Application\Rules\Calendar\HolidayCatalogue` (final class)

```php
public function __construct(string $folder);
public function countries(): array;
public function load(string $country): HolidayList;
```

#### `Ritmo\Application\Rules\Calendar\HolidayList` (final class)

```php
const FORMAT = 'ritmo-revenue-holidays';
public readonly string $country;
public readonly array $names;
public readonly array $daysOff;
public readonly bool $bridges;
public readonly array $holidays;
public static function fromArray(array $data): self;
public function datesIn(int $year): array;
public static function name(array $names, string $locale): string;
```

#### `Ritmo\Application\Rules\Calendar\HolidayProposal` (final class)

```php
public readonly array $holidays;
public readonly DateTimeImmutable $firstDayOff;
public readonly DateTimeImmutable $lastDayOff;
public readonly bool $onWeekend;
public readonly bool $alreadyListed;
public function __construct(array $holidays, DateTimeImmutable $firstDayOff, DateTimeImmutable $lastDayOff, bool $onWeekend, bool $alreadyListed = false);
public function firstNight(): DateTimeImmutable;
public function lastNight(): DateTimeImmutable;
public function nights(): int;
public function ticked(): bool;
public function name(string $locale): string;
public function key(): string;
public function toPeriod(string $locale): array;
public function markListed(array $periods): self;
```

#### `Ritmo\Application\Rules\Calendar\HolidayProposals` (final class)

```php
public static function forYears(HolidayList $list, array $years, bool $bridges): array;
```

#### `Ritmo\Application\Rules\FieldChange` (final class)

```php
const CHANGED = 'changed';
const ADDED = 'added';
const REMOVED = 'removed';
public readonly string $path;
public readonly ?string $key;
public readonly string $kind;
public readonly mixed $before;
public readonly mixed $after;
public function __construct(string $path, ?string $key, string $kind, mixed $before, mixed $after);
```

#### `Ritmo\Application\Rules\Orders\OrderBoard` (final class)

```php
const ENDED_DAYS = 180;
public function __construct(Clock $clock, OrderLedgerRepository $ledger, DetectedFreezeRepository $freezes);
public function active(string $propertyId, DateTimeImmutable $today): array;
public function freezes(string $propertyId, DateTimeImmutable $today): array;
public function ended(string $propertyId, DateTimeImmutable $today): array;
```

#### `Ritmo\Application\Rules\Orders\OrderDesk` (final class)

```php
public function __construct(Database $db, Clock $clock, PropertyRepository $properties, RoomTypeRepository $rooms, RuleSetRepository $ruleSets, OrderRepository $orders, OrderLedgerRepository $ledger, DetectedFreezeRepository $freezes, EditorEventRepository $events, OperationEventRepository $operationEvents);
public function create(string $propertyId, array $fields, int $userId, ?string $typed, bool $mayRelease, ?int $endOriginal = null): int;
public function end(string $propertyId, int $orderId, int $userId): void;
public function releaseFreeze(string $propertyId, int $freezeId, int $userId): void;
```

#### `Ritmo\Application\Rules\Orders\OrderForm` (final class)

```php
const TYPES = ['fixed_price', 'floor', 'target', 'rule', 'restriction', 'follow_up'];
const SAFETY_RELEASES = ['listing', 'below_standard_floor', 'above_weekly_rise_limit'];
const NOTE_MAX = 500;
public static function toOrder(array $fields, array $roomCodes, float $defaultPercentile): array;
```

#### `Ritmo\Application\Rules\PendingApprovals` (interface)

```php
public function waitingNights(string $propertyId): int;
public function expireAll(string $propertyId, string $reason): int;
```

#### `Ritmo\Application\Rules\Percent` (final class)

```php
public static function toRatio(string $text): float;
public static function fromRatio(float $ratio): string;
```

#### `Ritmo\Application\Rules\PrivacyCheck` (final class)

```php
const EMAIL = 'email';
const PHONE = 'phone';
const DOCUMENT = 'document';
const TOO_LONG = 'too_long';
public static function problem(string $text, int $maxLength): ?string;
```

#### `Ritmo\Application\Rules\RuleChecks` (final class)

```php
public function __construct(PropertyRepository $properties, RoomTypeRepository $rooms);
public function errors(array $candidate): array;
```

#### `Ritmo\Application\Rules\RuleDiff` (final class)

```php
public static function between(array $from, array $to, Schema $schema, bool $listsAsUnits = false): array;
public static function same(mixed $a, mixed $b): bool;
```

#### `Ritmo\Application\Rules\RuleDraft` (final class)

```php
const LIFETIME_DAYS = 7;
public readonly int $baseVersion;
public readonly string $baseHash;
public readonly array $rules;
public readonly array $touched;
public readonly DateTimeImmutable $updatedAt;
public readonly string $source;
public readonly ?int $restoredFrom;
public function __construct(int $baseVersion, string $baseHash, array $rules, array $touched, DateTimeImmutable $updatedAt, string $source = 'editor', ?int $restoredFrom = null);
public function with(array $rules, array $touched, DateTimeImmutable $at): self;
public function isExpired(DateTimeImmutable $now): bool;
public function toArray(): array;
public static function fromArray(array $stored): ?self;
```

#### `Ritmo\Application\Rules\RuleDraftStorage` (interface)

```php
public function get(int $userId): ?RuleDraft;
public function put(int $userId, RuleDraft $draft): void;
public function clear(int $userId): void;
```

#### `Ritmo\Application\Rules\RuleMerge` (final class)

```php
public static function merge(array $base, array $mine, array $theirs, Schema $schema): array;
```

#### `Ritmo\Application\Rules\RuleSetEditor` (final class)

```php
const RULES_CHANGED = 'rules_changed';
const NOTE_MAX = 190;
public function __construct(Database $db, Clock $clock, RuleSetRepository $ruleSets, PropertyRepository $properties, EditorEventRepository $events, RuleChecks $checks, PendingApprovals $approvals);
public function save(array $candidate, int $baseVersion, string $baseHash, int $userId, string $note, ?string $typed, bool $mayChangeSafety, string $source = 'editor', ?int $restoredFrom = null): SavedVersion;
public function waitingNights(): array;
```

#### `Ritmo\Application\Rules\RulesRefused` (final class extends RuntimeException)

```php
const SAFETY_NOT_ALLOWED = 'safety_not_allowed';
const CONFIRMATION = 'confirmation';
const CONFLICT = 'conflict';
const NOTE = 'note';
const NO_DRAFT = 'no_draft';
const UNKNOWN_VERSION = 'unknown_version';
const RELEASE_NOT_ALLOWED = 'release_not_allowed';
const UNKNOWN_ORDER = 'unknown_order';
public readonly string $reason;
public readonly array $params;
public function __construct(string $reason, array $params = []);
```

#### `Ritmo\Application\Rules\SafetyFields` (final class)

```php
const KEYS = ['safety.weekly_listing_change_max', 'safety.never_raise_today', 'floors.fraction_of_reference_min', 'write.listing_changes_enabled', 'modes.autonomous_enabled', 'quota.*'];
public static function covers(?string $key): bool;
public static function of(array $changes): array;
public static function confirmation(array $changes): string;
```

#### `Ritmo\Application\Rules\SavedVersion` (final class)

```php
public readonly int $version;
public readonly ?int $previous;
public readonly int $changes;
public readonly int $closedApprovals;
public readonly bool $unchanged;
public function __construct(int $version, ?int $previous, int $changes, int $closedApprovals, bool $unchanged);
```

#### `Ritmo\Application\Rules\VersionHistory` (final class)

```php
const PER_PAGE = 20;
public function __construct(RuleSetRepository $ruleSets, EditorEventRepository $events, PropertyRepository $properties);
public function page(int $page): array;
public function compare(int $from, int $to): array;
public function rules(int $version): RuleSet;
```

#### `Ritmo\Application\Run\BookingsUnwatched` (final class extends RuntimeException)

```php
// no public members
```

#### `Ritmo\Application\Run\CurrentSchedule` (final class implements ScheduleSource)

```php
public function __construct(RuleSetRepository $ruleSets);
public function schedule(Property $property): ScheduleConfig;
```

#### `Ritmo\Application\Run\JournalNights` (final class)

```php
public static function confirmed(array $rows, DateTimeImmutable $writtenAt): array;
public static function fields(?array $state, bool $pms): NightFields;
```

#### `Ritmo\Application\Run\JournalWatch` (final class)

```php
public function __construct(ConnectorSource $connectors, ResumeService $resume, RecordService $record, AlertService $alerts, Clock $clock);
public function settle(RunContext $context): ResumeReport;
public static function ownNights(ResumeReport $settled): array;
```

#### `Ritmo\Application\Run\LightScope` (final class)

```php
public function __construct(LightRoundService $service, RecalcGuard $quota, RunRepository $runs, QuotaRunStart $runStart);
public function scope(RunContext $context, array $movements, array $inventory, array $changes, int $newOverbooked): array;
```

#### `Ritmo\Application\Run\ListingWatch` (final class)

```php
public function __construct(RoomTypeRepository $roomTypes, WriteOpRepository $ops, OperationEventRepository $events, AlertService $alerts, Clock $clock);
public function inspect(RunContext $context): array;
```

#### `Ritmo\Application\Run\PipelineRunExecutor` (final class implements RunExecutor)

```php
public function __construct(RunOpener $opener, RunRepository $runs, RunPipeline $pipeline);
public function start(Property $property, RunKind $kind, bool $forcedShadow, DateTimeImmutable $today, DateTimeImmutable $deadline, Closure $heartbeat): LaunchResult;
public function resume(Property $property, int $runId, DateTimeImmutable $deadline, Closure $heartbeat): LaunchResult;
```

#### `Ritmo\Application\Run\PlanRebuild` (final class)

```php
public function __construct(RunInputsReader $inputs, Clock $clock, string $pmsName, StayWriteStep $stay);
public function rebuild(RunContext $context): RebuiltPlan;
```

#### `Ritmo\Application\Run\PushedFields` (final class)

```php
public static function of(PricingSync $sync, StaySyncCapability $capability, array $pricing, array $overrides): array;
```

#### `Ritmo\Application\Run\QuotaRunStart` (final class)

```php
public function __construct(ScheduledRunRepository $slots, RunRepository $runs, RecalcGuard $guard, Clock $clock);
public function of(RunContext $context): DateTimeImmutable;
```

#### `Ritmo\Application\Run\RebuiltPlan` (final class)

```php
public readonly RunInputs $inputs;
public readonly AssembledPlan $assembled;
public readonly ?StayAssembly $stay;
public function __construct(RunInputs $inputs, PlanAssembler $assembler, AssembledPlan $assembled, RuleSet $rules, Clock $clock, ?StayAssembly $stay = null);
public function validated(): ?ValidatedPlan;
public function revalidate(Plan $plan): ?ValidatedPlan;
public function calendar(): FreshCalendar;
public static function proposals(Plan $plan): array;
```

#### `Ritmo\Application\Run\RecalcRoomQuota` (final class implements RoomQuota, ConfirmationQuota)

```php
public function __construct(RecalcGuard $guard, array $listings, DateTimeImmutable $runStart);
public function permits(string $roomCode): bool;
public function allows(string $roomCode, DateTimeImmutable $now): bool;
```

#### `Ritmo\Application\Run\ReportStage` (final class implements AfterWriteStage, Stage)

```php
const NAME = 'report';
public function __construct(RunEndChecks $checks);
public function name(): string;
public function run(RunContext $context): StageResult;
```

#### `Ritmo\Application\Run\RunEndChecks` (final class)

```php
public function __construct(MarketReminder $market, SnapshotStore $snapshots, AlertService $alerts, AlertLedger $ledger);
public function check(RunContext $context, array $verify): ?int;
```

#### `Ritmo\Application\Run\RunOpener` (final class)

```php
public function __construct(RoomTypeRepository $roomTypes, RuleSetRepository $ruleSets, RunRepository $runs, Clock $clock, Logger $logger);
public function open(Property $property, string $kind, PropertyMode $mode, DateTimeImmutable $today): RunContext|RunOutcome;
public function reopen(Property $property, array $open): RunContext;
```

#### `Ritmo\Application\Run\RunStack` (final class)

```php
const MAX_EMAILS_PER_HOUR = 6;
public readonly Database $db;
public readonly Clock $clock;
public readonly KeyValueStore $store;
public readonly Logger $logger;
public readonly ConnectorSource $connectors;
public readonly EmergencySwitch $switch;
public readonly WriteGate $gate;
public readonly CapabilityCheck $capabilities;
public function __construct(Database $db, Clock $clock, KeyValueStore $store, Logger $logger, ConnectorSource $connectors, EmergencySwitch $switch, WriteGate $gate, CapabilityCheck $capabilities, Mailer $mailer, RecipientDirectory $recipients, Sleeper $sleeper, ?Closure $wake = null, ?Closure $timeLeft = null);
public function rules(): RuleSet;
public function properties(): PropertyRepository;
public function roomTypes(): RoomTypeRepository;
public function ruleSets(): RuleSetRepository;
public function runs(): RunRepository;
public function planItems(): PlanItemRepository;
public function snapshots(): InventorySnapshotRepository;
public function bookings(): BookingRepository;
public function writeOps(): WriteOpRepository;
public function changeLog(): ChangeLogRepository;
public function writtenSteps(): WrittenStepRepository;
public function approvalRequests(): PlanApprovalRepository;
public function events(): OperationEventRepository;
public function pauses(): PauseService;
public function preconditions(): ModePreconditions;
public function holds(): WaveHoldRepository;
public function freezes(): DetectedFreezeRepository;
public function observations(): NightObservationRepository;
public function slots(): ScheduledRunRepository;
public function states(): OperationStateRepository;
public function locks(): DbLock;
public function alerts(): AlertService;
public function recalcGuard(): RecalcGuard;
public function quotaRunStart(): QuotaRunStart;
public function inputs(): RunInputsReader;
public function stayWrites(): StayWriteStep;
public function marketRuler(): MarketRulerAssembler;
public function marketSeries(): MarketSeriesService;
public function pipeline(): RunPipeline;
public function opener(): RunOpener;
public function launcher(): LockedRunLauncher;
public function runNow(): RunNowService;
public function shadowRuns(): ShadowRunService;
public function tick(): SchedulerTick;
public function stallMonitor(): StallMonitor;
public function approvalDesk(): ApprovalDesk;
public function modes(): ModeService;
public function emergency(): EmergencyControl;
public function undo(): UndoService;
public function executor(): WriteExecutor;
public function undoWriter(): UndoWriter;
```

#### `Ritmo\Application\Run\StayAssembly` (final class)

```php
public readonly AssembledPlan $assembled;
public readonly ?PmsWriteBatch $batch;
public function __construct(AssembledPlan $assembled, ?PmsWriteBatch $batch);
public function summary(): array;
```

#### `Ritmo\Application\Run\StayWriteStep` (final class)

```php
public function __construct(InventorySnapshotRepository $snapshots, StaySyncCapability $capability, Clock $clock);
public function apply(RunContext $context, RunInputs $inputs, PlanAssembler $assembler, AssembledPlan $assembled): StayAssembly;
```

#### `Ritmo\Application\Run\UndoWriter` (final class)

```php
const KIND = 'undo';
public function __construct(PropertyRepository $properties, RoomTypeRepository $roomTypes, RunRepository $runs, OrderRepository $orders, UndoService $undo, RecordService $record, WriteOpRepository $ops, OperationEventRepository $events, ConnectorSource $connectors, WriteGate $gate, WriteExecutor $executor, EmergencySwitch $switch, DbLock $locks, CapabilityCheck $capabilities, Clock $clock);
public function undo(string $propertyId, array $changeIds, int $userId, RuleSet $rules): array;
```

#### `Ritmo\Application\Run\VerifyStage` (final class implements AfterWriteStage, Stage)

```php
const NAME = 'verify';
public function __construct(WriteOpRepository $ops, RecordService $record, WrittenStepRepository $steps, ConnectorSource $connectors, Clock $clock);
public function name(): string;
public function run(RunContext $context): StageResult;
```

#### `Ritmo\Application\Run\WatchStage` (final class implements Stage)

```php
const NAME = 'watch';
public function __construct(ConnectorSource $connectors, MovementTracker $movements, AlertService $alerts, WaveHoldRepository $holds, NightObservationRepository $observations, DetectedFreezeRepository $freezes, CapabilityCheck $capabilities, LightScope $light, InventorySnapshotRepository $snapshots, BookingRepository $bookings, JournalWatch $journal, ListingWatch $listings, Clock $clock);
public function name(): string;
public function run(RunContext $context): StageResult;
```

#### `Ritmo\Application\Run\WeeklyReference` (final class)

```php
public function __construct(KeyValueStore $store);
public function of(string $propertyId, array $listings, DateTimeImmutable $today): array;
```

#### `Ritmo\Application\Run\WriteInterrupted` (final class extends RuntimeException)

```php
// no public members
```

#### `Ritmo\Application\Run\WriteReport` (final class)

```php
public function __construct(AlertService $alerts, Clock $clock);
public function summarize(RunContext $context, WriteOutcome $outcome, string $actor): array;
```

#### `Ritmo\Application\Run\WriteRunner` (final class)

```php
public function __construct(ConnectorSource $connectors, WriteGate $gate, WriteExecutor $executor, WriteOpRepository $ops, QuotaRunStart $runStart, CapabilityCheck $capabilities, Clock $clock, ?Closure $timeLeft = null);
public function guardOnce(RunContext $context): void;
public function repairPlan(RunContext $context, RebuiltPlan $rebuilt, array $broken): ?ValidatedPlan;
public function write(RunContext $context, RebuiltPlan $rebuilt, ValidatedPlan $plan, WriteAuthorization $auth, Closure $stillAllowed, array $recalculate): WriteOutcome;
public static function staleListings(RunContext $context, ValidatedPlan $plan): array;
```

#### `Ritmo\Application\Run\WriteStage` (final class implements Stage)

```php
const NAME = 'write';
public function __construct(PlanRebuild $rebuild, WriteRunner $runner, PlanApprovalRepository $requests, WaveHoldRepository $holds, CapabilityCheck $capabilities, ModeService $modes, PropertyRepository $properties, AlertService $alerts, OperationEventRepository $events, WriteReport $report, Clock $clock);
public function name(): string;
public function run(RunContext $context): StageResult;
```

#### `Ritmo\Application\Schedule\FailureKind` (enum)

```php
case Connector = 'connector';
case Write = 'write';
case DayChanged = 'day_changed';
case Other = 'other';
```

#### `Ritmo\Application\Schedule\LaunchResult` (final class)

```php
public readonly LaunchStatus $status;
public readonly ?int $runId;
public readonly ?FailureKind $failure;
public readonly string $note;
public function __construct(LaunchStatus $status, ?int $runId = null, ?FailureKind $failure = null, string $note = '');
public static function busy(): self;
```

#### `Ritmo\Application\Schedule\LaunchStatus` (enum)

```php
case Running = 'running';
case Completed = 'completed';
case Failed = 'failed';
case Stopped = 'stopped';
case Busy = 'busy';
public function slotStatus(): SlotStatus;
public function isFinal(): bool;
```

#### `Ritmo\Application\Schedule\LightRoundRequest` (final class)

```php
public readonly string $propertyId;
public readonly array $rooms;
public readonly DateTimeImmutable $today;
public readonly int $horizonDays;
public readonly array $movements;
public readonly array $inventory;
public readonly DateTimeImmutable $readStartedAt;
public readonly array $manualChanges;
public readonly int $newOverbooked;
public readonly LightRunConfig $config;
public function __construct(string $propertyId, array $rooms, DateTimeImmutable $today, int $horizonDays, array $movements, array $inventory, DateTimeImmutable $readStartedAt, array $manualChanges, int $newOverbooked, LightRunConfig $config);
```

#### `Ritmo\Application\Schedule\LightRoundService` (final class)

```php
public function __construct(InventorySnapshotRepository $snapshots, AlertService $alerts);
public function plan(LightRoundRequest $request, RoomQuota $quota, ?int $runId = null): LightRoundPlan;
```

#### `Ritmo\Application\Schedule\LockedRunLauncher` (final class implements RunLauncher)

```php
const LOCK_TTL_SECONDS = 600;
public function __construct(RunRepository $runs, DbLock $locks, RunExecutor $executor, Clock $clock, Logger $logger);
public static function lockKey(string $propertyId): string;
public function continueOpen(Property $property, int $budgetSeconds): ?LaunchResult;
public function stopOpen(Property $property, string $reason): ?LaunchResult;
public function start(Property $property, RunKind $kind, bool $forcedShadow, int $budgetSeconds): LaunchResult;
```

#### `Ritmo\Application\Schedule\MovementRead` (final class)

```php
public readonly MovementWindow $window;
public readonly array $bookings;
public readonly ?MovementBatch $batch;
public readonly ?ConnectorException $failure;
public static function read(MovementWindow $window, array $bookings, MovementBatch $batch): self;
public static function failed(MovementWindow $window, ConnectorException $failure): self;
public function succeeded(): bool;
```

#### `Ritmo\Application\Schedule\MovementTracker` (final class)

```php
const RETRY_AFTER_SECONDS = 10;
public function __construct(OperationStateRepository $states, BookingMovementRepository $ledger, AlertService $alerts, Sleeper $sleeper, Clock $clock);
public function read(string $propertyId, PmsConnector $pms, array $rooms, LightRunConfig $config, int $maxLookbackDays, ?int $runId = null): MovementRead;
```

#### `Ritmo\Application\Schedule\PauseService` (final class)

```php
const MAX_REASON = 190;
public function __construct(PropertyRepository $properties, OperationStateRepository $states, Clock $clock, Logger $logger, OperationEventRepository $events);
public function pause(string $propertyId, ?DateTimeImmutable $until, string $reason, int $userId): void;
public function resume(string $propertyId, int $userId): void;
```

#### `Ritmo\Application\Schedule\RoomQuota` (interface)

```php
public function permits(string $roomCode): bool;
```

#### `Ritmo\Application\Schedule\RunExecutor` (interface)

```php
public function start(Property $property, RunKind $kind, bool $forcedShadow, DateTimeImmutable $today, DateTimeImmutable $deadline, Closure $heartbeat): LaunchResult;
public function resume(Property $property, int $runId, DateTimeImmutable $deadline, Closure $heartbeat): LaunchResult;
```

#### `Ritmo\Application\Schedule\RunLauncher` (interface)

```php
public function continueOpen(Property $property, int $budgetSeconds): ?LaunchResult;
public function stopOpen(Property $property, string $reason): ?LaunchResult;
public function start(Property $property, RunKind $kind, bool $forcedShadow, int $budgetSeconds): LaunchResult;
```

#### `Ritmo\Application\Schedule\RunNowService` (final class)

```php
const DEFAULT_BUDGET_SECONDS = 20;
public function __construct(PropertyRepository $properties, RunLauncher $launcher, EmergencySwitch $switch);
public function run(string $propertyId, int $budgetSeconds = 20): LaunchResult;
```

#### `Ritmo\Application\Schedule\ScheduleHousekeeping` (final class)

```php
const DAY_KEY = 'scheduler_housekeeping_day';
const SLOT_RETENTION_DAYS = 90;
const MOVEMENT_RETENTION_DAYS = 8;
public function __construct(ScheduledRunRepository $slots, BookingMovementRepository $movements, KeyValueStore $store, Clock $clock);
public function daily(): bool;
```

#### `Ritmo\Application\Schedule\ScheduleSource` (interface)

```php
public function schedule(Property $property): ScheduleConfig;
```

#### `Ritmo\Application\Schedule\SchedulerTick` (final class)

```php
const TICK_LOCK = 'scheduler_tick';
const HEARTBEAT_KEY = 'scheduler_last_tick';
const DEFAULT_BUDGET_SECONDS = 40;
public function __construct(PropertyRepository $properties, ScheduledRunRepository $slots, OperationStateRepository $states, RunRepository $runs, DbLock $locks, KeyValueStore $store, EmergencySwitch $switch, RunLauncher $launcher, ScheduleSource $schedules, AlertService $alerts, ScheduleHousekeeping $housekeeping, Clock $clock, Logger $logger);
public function run(int $budgetSeconds = 40): TickReport;
```

#### `Ritmo\Application\Schedule\StallMonitor` (final class)

```php
public function __construct(KeyValueStore $store, ScheduledRunRepository $slots, AlertService $alerts, Clock $clock);
public function check(int $stalledAfterMinutes): bool;
public function heartbeat(): ?DateTimeImmutable;
```

#### `Ritmo\Application\Schedule\TickReport` (final class)

```php
const IDLE = 'idle';
const CONTINUED = 'continued';
const STARTED = 'started';
const WAITING = 'waiting';
const BLOCKED = 'blocked';
const DEFERRED = 'deferred';
const ERROR = 'error';
public readonly bool $busy;
public readonly array $properties;
public readonly bool $failed;
public function __construct(bool $busy, array $properties = [], bool $failed = false);
```

#### `Ritmo\Application\Setup\SampleCsv` (final class)

```php
const MAX_LINES = 5000;
public static function parse(string $text): array;
```

#### `Ritmo\Application\Setup\SetupService` (final class)

```php
public function __construct(Database $db, PropertyRepository $properties, RoomTypeRepository $roomTypes, RuleSetRepository $ruleSets, PriceSampleRepository $samples, MarketAnchorRepository $anchors, Clock $clock);
public function createProperty(array $input): Property;
public function saveRoomType(array $input): RoomType;
public function importPreset(string $json, int $userId = 0): array;
public function importSamples(string $propertyId, string $csv): array;
```

#### `Ritmo\Application\ShadowRun\AfterWriteStage` (interface)

```php
// no public members
```

#### `Ritmo\Application\ShadowRun\AssemblyState` (final class)

```php
public static function of(AssembledPlan $run, array $priceTraces, array $rooms): array;
```

#### `Ritmo\Application\ShadowRun\CollectStage` (final class implements Stage)

```php
const NAME = 'collect';
const FIRST_BOOKINGS_READ_DAYS = 365;
public function __construct(ConnectorSource $connectors, InventorySnapshotRepository $snapshots, BookingRepository $bookings, Clock $clock);
public function name(): string;
public function run(RunContext $context): StageResult;
```

#### `Ritmo\Application\ShadowRun\DecideStage` (final class implements Stage)

```php
const NAME = 'decide';
const NO_MARKET_DATA = 'no_market_data';
public function __construct(RunInputsReader $inputs, Clock $clock, string $pmsName, StayWriteStep $stay);
public function name(): string;
public function run(RunContext $context): StageResult;
```

#### `Ritmo\Application\ShadowRun\MeasureStage` (final class implements Stage)

```php
const NAME = 'measure';
const PUSH_STALE_HOURS = 30;
public function __construct(InventorySnapshotRepository $snapshots, BookingRepository $bookings, AlertRepository $alerts, Clock $clock);
public function name(): string;
public function run(RunContext $context): StageResult;
```

#### `Ritmo\Application\ShadowRun\PlanItemStatus` (final class)

```php
const WOULD_WRITE = 'would_write';
const NO_CHANGE = 'no_change';
const REJECTED = 'rejected';
```

#### `Ritmo\Application\ShadowRun\PlanRefused` (final class extends RuntimeException)

```php
public static function withCodes(array $codes): self;
```

#### `Ritmo\Application\ShadowRun\PmsState` (final class)

```php
const SEALED = 'would_write';
const REFUSED = 'rejected';
const FROZEN = 'frozen';
public static function of(StayAssembly $stay): array;
```

#### `Ritmo\Application\ShadowRun\RunContext` (final class)

```php
public readonly array $rooms;
public readonly int $runId;
public readonly Property $property;
public readonly RuleSet $rules;
public readonly DateTimeImmutable $today;
public readonly DateRange $horizon;
public readonly string $stage;
public readonly array $summary;
public readonly array $state;
public readonly string $kind;
public readonly PropertyMode $mode;
public readonly ?int $rulesetVersion;
public function __construct(int $runId, Property $property, array $rooms, RuleSet $rules, DateTimeImmutable $today, DateRange $horizon, string $stage = '', array $summary = [], array $state = [], string $kind = 'shadow', PropertyMode $mode = PropertyMode::Shadow, ?int $rulesetVersion = null, ?Closure $heartbeat = null);
public function withHeartbeat(?Closure $heartbeat): self;
public function stillHeld(): bool;
public function after(string $stage, array $summary, array $state): self;
public function capturedAt(): ?DateTimeImmutable;
public function listings(): array;
public function pricingNights(): array;
public function overrides(): array;
public function readsPricingCalendar(): bool;
```

#### `Ritmo\Application\ShadowRun\RunInputsReader` (final class)

```php
public function __construct(InventorySnapshotRepository $snapshots, MarketAnchorRepository $anchors, StepLedgerRepository $ledger, OrderRepository $orders, MarketRulerAssembler $ruler, MarketSeriesService $marketSeries, ?ThirdPartyWatch $thirdParty = null, ?WeeklyReference $weekly = null);
public function read(RunContext $context, DateTimeImmutable $now): RunInputs;
public function priceTraces(): array;
```

#### `Ritmo\Application\ShadowRun\RunOutcome` (final class)

```php
const RUNNING = 'running';
const COMPLETED = 'completed';
const FAILED = 'failed';
const STOPPED = 'stopped';
const BUSY = 'busy';
const BLOCKED = 'blocked';
public readonly string $status;
public readonly ?int $runId;
public readonly string $stage;
public readonly ?string $error;
public function __construct(string $status, ?int $runId, string $stage = '', ?string $error = null);
public function isFinished(): bool;
public function toArray(): array;
```

#### `Ritmo\Application\ShadowRun\RunPipeline` (final class)

```php
public function __construct(array $stages, RunRepository $runs, Database $db, EmergencySwitch $switch, Clock $clock, Logger $logger);
public function stageNames(): array;
public function continue(RunContext $context, DateTimeImmutable $deadline, ?Closure $keepAlive = null): RunOutcome;
```

#### `Ritmo\Application\ShadowRun\ShadowRunService` (final class)

```php
const KIND = 'shadow';
const LOCK_TTL_SECONDS = 600;
const DEFAULT_BUDGET_SECONDS = 20;
public function __construct(PropertyRepository $properties, RunOpener $opener, RunRepository $runs, DbLock $locks, EmergencySwitch $switch, RunPipeline $pipeline, Clock $clock, Logger $logger);
public static function lockKey(string $propertyId): string;
public function run(string $propertyId, int $budgetSeconds = 20): RunOutcome;
public function resumeOpenRuns(int $budgetSeconds = 20): array;
```

#### `Ritmo\Application\ShadowRun\ShadowStage` (final class implements Stage)

```php
const NAME = 'shadow';
const DELETE_FIELD = 'delete';
public function __construct(PlanItemRepository $planItems, RunPayloadRepository $payloads);
public function name(): string;
public function run(RunContext $context): StageResult;
```

#### `Ritmo\Application\ShadowRun\Stage` (interface)

```php
public function name(): string;
public function run(RunContext $context): StageResult;
```

#### `Ritmo\Application\ShadowRun\StageResult` (final class)

```php
public readonly array $summary;
public readonly array $state;
public readonly ?Closure $persist;
public function __construct(array $summary, array $state = [], ?Closure $persist = null);
```

#### `Ritmo\Application\ShadowRun\StateCodec` (final class)

```php
public static function listing(ListingInfo $listing): array;
public static function override(DateOverride $override): array;
public static function toOverride(array $data): DateOverride;
public static function priceNight(PriceNight $night): array;
public static function toPriceNight(array $data): PriceNight;
public static function instant(?string $value): ?DateTimeImmutable;
```

#### `Ritmo\Application\ShadowRun\ValidateStage` (final class implements Stage)

```php
const NAME = 'validate';
public function name(): string;
public function run(RunContext $context): StageResult;
```

#### `Ritmo\Application\Verify\ConfirmationQuota` (interface)

```php
public function allows(string $roomCode, DateTimeImmutable $now): bool;
```

#### `Ritmo\Application\Verify\DeliveryService` (final class)

```php
public function __construct(WrittenStepRepository $steps, PricingConnector $pricing, PmsConnector $pms, ChannelArrival $arrival = \Ritmo\Domain\Write\Channel\ChannelArrival::__set_state(array(
   'overdueHours' => 30,
)));
public function confirm(string $propertyId, array $rooms, DateTimeImmutable $now): ArrivalReport;
```

#### `Ritmo\Application\Verify\NightConfirmation` (final class)

```php
public readonly WrittenNight $written;
public readonly StoredStatus $stored;
public readonly NightFields $observed;
public readonly array $differences;
public readonly ?LiveRoute $liveRoute;
public readonly ?LiveVerdict $live;
public function __construct(WrittenNight $written, StoredStatus $stored, NightFields $observed, array $differences = [], ?LiveRoute $liveRoute = null, ?LiveVerdict $live = null);
public function withLive(LiveRoute $route, ?LiveVerdict $verdict): self;
public function isStored(): bool;
```

#### `Ritmo\Application\Verify\RecordOutcome` (final class)

```php
public readonly array $changeIds;
public readonly array $stepIds;
public function __construct(array $changeIds, array $stepIds);
```

#### `Ritmo\Application\Verify\RecordService` (final class)

```php
public function __construct(ChangeLogRepository $changes, WrittenStepRepository $steps, NightObservationRepository $observations);
public function record(string $propertyId, array $confirmations, array $proposals, array $bases, string $actor, ?int $runId, DateTimeImmutable $storedAt): RecordOutcome;
```

#### `Ritmo\Application\Verify\StoredStatus` (enum)

```php
case Stored = 'stored';
case Mismatch = 'mismatch';
case Unread = 'unread';
```

#### `Ritmo\Application\Verify\ThirdPartyResult` (final class)

```php
public readonly array $changes;
public readonly array $freezes;
public readonly array $orders;
public function __construct(array $changes, array $freezes, array $orders);
```

#### `Ritmo\Application\Verify\ThirdPartyWatch` (final class)

```php
public function __construct(NightObservationRepository $observations, DetectedFreezeRepository $freezes, CapabilityCheck $capabilities, Clock $clock, ThirdPartyConfig $config = \Ritmo\Domain\Write\ThirdParty\ThirdPartyConfig::__set_state(array(
   'untilNightPasses' => false,
   'freezeDays' => 7,
)), ManualChangeDetector $detector = \Ritmo\Domain\Write\ThirdParty\ManualChangeDetector::__set_state(array(
)));
public function inspect(Property $property, array $current, array $unresolved = [], array $pushed = [], ?int $runId = null): ThirdPartyResult;
public function orders(Property $property): array;
public function release(int $freezeId, int $userId): bool;
```

#### `Ritmo\Application\Verify\UndoRequest` (final class)

```php
public readonly UndoDraft $draft;
public readonly array $refused;
public readonly array $grants;
public readonly ?ValidatedPlan $plan;
public function __construct(UndoDraft $draft, array $refused, array $grants, ?ValidatedPlan $plan = null);
```

#### `Ritmo\Application\Verify\UndoService` (final class)

```php
public function __construct(ChangeLogRepository $changes, WrittenStepRepository $steps, PricingConnector $pricing, PmsConnector $pms, ApprovalService $approvals, CapabilityCheck $capabilities, Clock $clock, UndoPlanner $planner = \Ritmo\Domain\Write\Change\UndoPlanner::__set_state(array(
   'items' => 
  array (
  ),
   'deletions' => 
  array (
  ),
   'listing' => 
  array (
  ),
   'restores' => 
  array (
  ),
   'skipped' => 
  array (
  ),
   'notes' => 
  array (
  ),
   'undoOf' => 
  array (
  ),
)));
public function request(Property $property, array $rooms, array $changeIds, int $userId, Validator $validator, ValidationContext $context, ?int $runId = null): UndoRequest;
public function confirm(array $changeIds, DateTimeImmutable $at): int;
```

#### `Ritmo\Application\Verify\Verifier` (final class)

```php
public function __construct(PricingConnector $pricing, PmsConnector $pms, LiveCheck $check = \Ritmo\Domain\Write\Confirm\LiveCheck::__set_state(array(
)));
public function stored(array $written, array $rooms): array;
public function live(array $confirmations, array $rooms, array $listings, ConfirmationQuota $quota, DateTimeImmutable $now): array;
```

#### `Ritmo\Application\Write\Calendar\PmsCalendarChanges` (final class)

```php
public static function of(array $accepted, array $rooms): array;
```

#### `Ritmo\Application\Write\Calendar\PmsWriteBatch` (final class)

```php
public readonly array $changes;
public readonly PmsCheckResult $check;
public readonly array $frozenOut;
public readonly int $mergeConflicts;
public function __construct(array $changes, PmsCheckResult $check, array $frozenOut, int $mergeConflicts);
public function alerts(): array;
```

#### `Ritmo\Application\Write\Calendar\StayWriteRound` (final class)

```php
public function __construct(PmsChangeCheck $check = \Ritmo\Domain\Stay\Write\PmsChangeCheck::__set_state(array(
)));
public function batch(StayWritePlan $plan, PmsCheckContext $context, array $rooms): PmsWriteBatch;
```

#### `Ritmo\Application\Write\Calendar\UndoPmsEntries` (final class)

```php
public function __construct(PricingSync $sync, StaySyncCapability $capability);
public function of(array $restores, array $readNow, array $pricingUndo): array;
```

#### `Ritmo\Application\Write\Execute\PmsGuard` (final class)

```php
const PRICING_FIRST = 'pricing_first';
const AT_TARGET = 'already_at_target';
const CHANGED = 'changed_since_read';
public function filter(WriteStep $step, array $pricingDone, StoredState $fresh): array;
```

#### `Ritmo\Application\Write\Execute\RunProgress` (final class)

```php
public function record(StepResult $result): void;
public function stop(StopReason $reason, ?WriteStep $step = null): void;
public function stopped(): ?StopReason;
public function pricingRateLimited(): bool;
public function alert(string $code): void;
public function notRecalculated(string $listing): void;
public function isNotRecalculated(string $listing): bool;
public function pricingDone(): array;
public function results(): array;
public function outcome(array $nearNights): WriteOutcome;
```

#### `Ritmo\Application\Write\Execute\StepResult` (final class)

```php
public readonly WriteStep $step;
public readonly OpStatus $status;
public readonly string $reason;
public readonly ?int $httpStatus;
public function __construct(WriteStep $step, OpStatus $status, string $reason = '', ?int $httpStatus = null);
public function with(OpStatus $status, string $reason = ''): self;
```

#### `Ritmo\Application\Write\Execute\StepSender` (final class)

```php
public function send(WriteRun $run, WriteStep $step): void;
public static function classify(Throwable $failure): array;
```

#### `Ritmo\Application\Write\Execute\StopReason` (enum)

```php
case LockLost = 'lock_lost';
case KillSwitch = 'kill_switch';
case DayTurned = 'day_turned';
case TokenExpired = 'token_expired';
case TimeBudget = 'time_budget';
case Rejected = 'rejected';
case NotReady = 'not_ready';
case RateLimited = 'rate_limited';
case AuthFailed = 'auth_failed';
case UpstreamError = 'upstream_error';
case Unknown = 'unknown';
case Blocked = 'blocked';
case ReadFailed = 'read_failed';
```

#### `Ritmo\Application\Write\Execute\StoredCheck` (final class)

```php
public static function compare(array $intended, StoredState $fresh, bool $pms): array;
```

#### `Ritmo\Application\Write\Execute\WriteExecutor` (final class)

```php
public function __construct(WriteGate $gate, EmergencySwitch $switch, Clock $clock, WriteJournal $journal, WriteConfig $config = \Ritmo\Domain\Write\WriteConfig::__set_state(array(
   'maxReadAgeMinutes' => 30,
   'maxValidationAgeMinutes' => 10,
   'tokenTtlMinutes' => 15,
   'horizonDays' => 365,
   'horizonEdgeDays' => 2,
   'maxIdenticalRefusals' => 2,
   'listingChangesEnabled' => false,
   'pmsMaxRangesPerPost' => 100,
   'callTimeoutSeconds' => 120,
   'maxDatesPerCall' => 70,
)), ?RecalcGuard $recalc = null);
public function execute(WriteRun $run): WriteOutcome;
```

#### `Ritmo\Application\Write\Execute\WriteOutcome` (final class)

```php
public readonly array $results;
public readonly ?StopReason $stopReason;
public readonly array $alerts;
public readonly array $brokenGroups;
public function __construct(array $results, ?StopReason $stopReason, array $alerts, array $brokenGroups = []);
public function stepsWith(OpStatus $status): array;
public function statusOf(int $number): ?OpStatus;
```

#### `Ritmo\Application\Write\Execute\WriteRun` (final class)

```php
public readonly int $runId;
public readonly Property $property;
public readonly ValidatedPlan $plan;
public readonly IssuedToken $issued;
public readonly PricingConnector $pricing;
public readonly ?PmsConnector $pms;
public readonly Closure $heartbeat;
public readonly ?DateTimeImmutable $deadline;
public readonly ?DateTimeImmutable $runStart;
public function __construct(int $runId, Property $property, ValidatedPlan $plan, IssuedToken $issued, PricingConnector $pricing, ?PmsConnector $pms, Closure $heartbeat, ?DateTimeImmutable $deadline = null, ?DateTimeImmutable $runStart = null);
```

#### `Ritmo\Application\Write\Gate\AutonomousAuthorization` (final class implements WriteAuthorization)

```php
public readonly string $propertyId;
public readonly int $enabledBy;
public readonly DateTimeImmutable $enabledAt;
public function __construct(string $propertyId, int $enabledBy, DateTimeImmutable $enabledAt);
public function refusal(WriteContext $context, string $planHash, DateTimeImmutable $today): ?string;
public function isHuman(): bool;
public function allowsListingChange(string $roomCode, string $field): bool;
public function actor(): string;
```

#### `Ritmo\Application\Write\Gate\FieldLocks` (final class)

```php
public function check(array $steps, array $rooms): void;
```

#### `Ritmo\Application\Write\Gate\HumanApproval` (final class implements WriteAuthorization)

```php
public readonly string $id;
public readonly string $propertyId;
public readonly string $planHash;
public readonly DateTimeImmutable $today;
public readonly int $approvedBy;
public readonly DateTimeImmutable $approvedAt;
public readonly array $listingChanges;
public function __construct(string $id, string $propertyId, string $planHash, DateTimeImmutable $today, int $approvedBy, DateTimeImmutable $approvedAt, array $listingChanges = []);
public function refusal(WriteContext $context, string $planHash, DateTimeImmutable $today): ?string;
public function isHuman(): bool;
public function allowsListingChange(string $roomCode, string $field): bool;
public function actor(): string;
```

#### `Ritmo\Application\Write\Gate\IssuedToken` (final class)

```php
public readonly WriteToken $token;
public readonly array $steps;
public readonly array $dropped;
public readonly string $planHash;
public readonly DateTimeImmutable $expiresAt;
public readonly string $actor;
public function __construct(WriteToken $token, array $steps, array $dropped, string $planHash, DateTimeImmutable $expiresAt, string $actor);
public function journalId(): string;
```

#### `Ritmo\Application\Write\Gate\PlanHash` (final class)

```php
public static function of(Plan $plan): string;
```

#### `Ritmo\Application\Write\Gate\TokenGrant` (final class)

```php
public readonly string $propertyId;
public readonly string $timezone;
public readonly string $today;
public readonly DateTimeImmutable $expiresAt;
public readonly array $steps;
public function __construct(string $propertyId, string $timezone, string $today, DateTimeImmutable $expiresAt, array $steps);
public function revoke(): void;
public function isRevoked(): bool;
public function use(WriteRequest $request): bool;
public function claim(string $signature): bool;
```

#### `Ritmo\Application\Write\Gate\TonightGuard` (final class)

```php
public function check(array $steps, string $today, array $tonight, array $bases): void;
```

#### `Ritmo\Application\Write\Gate\TonightState` (final class)

```php
public readonly string $roomCode;
public readonly ?float $price;
public readonly ?DateOverride $rule;
public function __construct(string $roomCode, ?float $price, ?DateOverride $rule);
public function ruleHoldsThePriceDown(): bool;
```

#### `Ritmo\Application\Write\Gate\WriteAuthorization` (interface)

```php
public function refusal(WriteContext $context, string $planHash, DateTimeImmutable $today): ?string;
public function isHuman(): bool;
public function allowsListingChange(string $roomCode, string $field): bool;
public function actor(): string;
```

#### `Ritmo\Application\Write\Gate\WriteContext` (final class)

```php
public readonly array $rooms;
public readonly Property $property;
public readonly DateTimeImmutable $readAt;
public readonly array $tonight;
public readonly Closure $lockHeld;
public readonly string $pricingPms;
public readonly bool $licenceAllowsWrites;
public readonly ?Closure $canApprove;
public function __construct(Property $property, array $rooms, DateTimeImmutable $readAt, array $tonight, Closure $lockHeld, string $pricingPms, bool $licenceAllowsWrites = true, ?Closure $canApprove = null);
public function bases(): array;
public function userCanApprove(int $userId): bool;
```

#### `Ritmo\Application\Write\Gate\WriteGate` (final class implements WriteAuthority)

```php
public function __construct(Clock $clock, EmergencySwitch $switch, WriteConfig $config = \Ritmo\Domain\Write\WriteConfig::__set_state(array(
   'maxReadAgeMinutes' => 30,
   'maxValidationAgeMinutes' => 10,
   'tokenTtlMinutes' => 15,
   'horizonDays' => 365,
   'horizonEdgeDays' => 2,
   'maxIdenticalRefusals' => 2,
   'listingChangesEnabled' => false,
   'pmsMaxRangesPerPost' => 100,
   'callTimeoutSeconds' => 120,
   'maxDatesPerCall' => 70,
)), PlanConfig $planConfig = \Ritmo\Domain\Plan\PlanConfig::__set_state(array(
   'calendarMaxAgeHours' => 6.0,
   'occupancyReasonCodes' => 
  array (
    0 => 'SOLD_OUT_PROTECTION',
    1 => 'RELATION_DISCOUNT_BAND',
    2 => 'RELATION_OCCUPANCY_SWITCH',
  ),
   'riseWarningPercent' => 15.0,
   'riseExemptReasonCodes' => 
  array (
    0 => 'SOLD_OUT_PROTECTION',
  ),
   'maxMinStay' => 30,
   'marketEvidenceMinLength' => 20,
   'noDiscountRooms' => 
  array (
  ),
   'lastMinute' => 
  \Ritmo\Domain\Plan\LastMinuteRule::__set_state(array(
     'enabled' => false,
     'rooms' => 
    array (
    ),
     'maxDaysOut' => 0,
     'maxDiscount' => 0.0,
     'maxSoldShare' => 0.0,
     'lastUnitOnlyRooms' => 
    array (
    ),
  )),
   'reasonMaxLength' => 250,
   'sliceMaxDates' => 70,
   'horizonNights' => 60,
)));
public function issue(ValidatedPlan $plan, WriteAuthorization $auth, WriteContext $context, array $recalculate = []): IssuedToken;
public function isLive(WriteToken $token): bool;
public function verify(WriteToken $token, WriteRequest $request): void;
public function claim(WriteToken $token, string $method, string $url, ?string $body): bool;
public function revoke(WriteToken $token): void;
```

#### `Ritmo\Application\Write\Gate\WriteRefused` (final class extends DomainException)

```php
const UNSEALED_PLAN = 'unsealed_plan';
const WRONG_PROPERTY = 'wrong_property';
const STALE_PLAN = 'stale_plan';
const STALE_VALIDATION = 'stale_validation';
const STALE_READS = 'stale_reads';
const BASE_CHANGED = 'base_changed';
const KILL_SWITCH = 'kill_switch';
const MODE_SHADOW = 'mode_shadow';
const MODE_NOT_AUTONOMOUS = 'mode_not_autonomous';
const LICENCE = 'licence';
const NOT_APPROVED = 'not_approved';
const APPROVAL_MISMATCH = 'approval_mismatch';
const APPROVAL_STALE = 'approval_stale';
const APPROVER_LACKS_CAPABILITY = 'approver_lacks_capability';
const RAISE_TODAY_FLOOR = 'raise_today_floor';
const RAISE_TODAY_CEILING = 'raise_today_ceiling';
const RAISE_TODAY_DELETION = 'raise_today_deletion';
const TONIGHT_UNKNOWN = 'tonight_unknown';
const FORBIDDEN_FIELD = 'forbidden_field';
const LOCK_NOT_HELD = 'lock_not_held';
const NOTHING_TO_WRITE = 'nothing_to_write';
const LISTING_NEEDS_APPROVAL = 'listing_needs_approval';
public readonly string $reason;
public readonly string $subject;
public function __construct(string $reason, string $message, string $subject = '');
```

#### `Ritmo\Application\Write\Journal\NightRule` (final class)

```php
public static function ofOverride(?DateOverride $override): ?array;
public static function sent(array $entry): array;
public static function merged(?array $before, array $sent): array;
public static function ofInventory(InventoryNight $night): array;
public static function holds(?array $current, ?array $intended, array $fields): bool;
```

#### `Ritmo\Application\Write\Journal\OpStatus` (enum)

```php
case Planned = 'planned';
case Sending = 'sending';
case Accepted = 'accepted';
case Stored = 'stored';
case Rejected = 'rejected';
case NotReady = 'not_ready';
case RateLimited = 'rate_limited';
case Unknown = 'unknown';
case Mismatch = 'mismatch';
case Skipped = 'skipped';
case Abandoned = 'abandoned';
case Quarantined = 'quarantined';
```

#### `Ritmo\Application\Write\Journal\StateReader` (final class)

```php
public function __construct(?PricingConnector $pricing, ?PmsConnector $pms);
public function forSteps(array $steps): StoredState;
public function read(array $pricing, array $pms): StoredState;
```

#### `Ritmo\Application\Write\Journal\StoredState` (final class)

```php
public readonly array $pricing;
public readonly array $pms;
public function __construct(array $pricing = [], array $pms = []);
public function before(WriteStep $step): array;
public function intended(WriteStep $step): array;
```

#### `Ritmo\Application\Write\Journal\WriteJournal` (final class)

```php
public function __construct(WriteOpRepository $ops, Clock $clock);
public function plan(int $runId, string $propertyCode, IssuedToken $issued, StoredState $before, ?ValidatedPlan $plan = null): array;
public function sending(int $row): void;
public function finish(int $row, OpStatus $status, ?int $httpStatus = null, ?string $error = null): void;
public function observe(int $row, OpStatus $status, array $observed): void;
public function refusals(string $propertyCode, WriteStep $step): int;
public static function bodyHash(WriteStep $step): string;
```

#### `Ritmo\Application\Write\Quota\AttemptLog` (final class)

```php
public function __construct(int $futureSlackSeconds = 300);
public function accept(array $recorded, RecalcAttempt $attempt, DateTimeImmutable $now): ?RecalcAttempt;
public static function instant(string $text): DateTimeImmutable;
```

#### `Ritmo\Application\Write\Quota\AttemptOutcome` (enum)

```php
case Pending = 'pending';
case Ok = 'ok';
case Failure = 'failure';
case RateLimited = 'rate_limited';
```

#### `Ritmo\Application\Write\Quota\AttemptRefused` (final class extends DomainException)

```php
// no public members
```

#### `Ritmo\Application\Write\Quota\QuotaVerdict` (final class)

```php
const ROOM_QUOTA_SPENT = 'room_quota_spent';
const ACCOUNT_MINUTE_LIMIT = 'account_minute_limit';
const ACCOUNT_429_WAIT = 'account_429_wait';
const ROOM_429_THIS_RUN = 'room_429_this_run';
const ROOM_UPSTREAM_UNAVAILABLE_THIS_RUN = 'room_upstream_unavailable_this_run';
const ROOM_FAILURE_THIS_RUN = 'room_failure_this_run';
public readonly bool $allowed;
public readonly array $blockedBy;
public readonly int $used;
public readonly int $left;
public readonly array $byResult;
public readonly ?DateTimeImmutable $roomSlotOpensAt;
public readonly ?DateTimeImmutable $accountSlotOpensAt;
public readonly ?DateTimeImmutable $account429WaitUntil;
public readonly int $accountLastMinute;
public readonly bool $morningSlotWarning;
public readonly DateTimeImmutable $runStart;
public function __construct(bool $allowed, array $blockedBy, int $used, int $left, array $byResult, ?DateTimeImmutable $roomSlotOpensAt, ?DateTimeImmutable $accountSlotOpensAt, ?DateTimeImmutable $account429WaitUntil, int $accountLastMinute, bool $morningSlotWarning, DateTimeImmutable $runStart);
```

#### `Ritmo\Application\Write\Quota\RecalcAttempt` (final class)

```php
const ERROR_LENGTH = 300;
public readonly DateTimeImmutable $at;
public readonly ?string $error;
public readonly string $listing;
public readonly AttemptOutcome $outcome;
public readonly ?string $responseId;
public readonly ?int $id;
public function __construct(string $listing, DateTimeImmutable $at, AttemptOutcome $outcome, ?string $error = null, ?string $responseId = null, ?int $id = null);
public function effectiveOutcome(DateTimeImmutable $now, int $pendingTimeoutSeconds): AttemptOutcome;
```

#### `Ritmo\Application\Write\Quota\RecalcGuard` (final class)

```php
public function __construct(RecalcAttemptRepository $attempts, Clock $clock, string $provider, QuotaConfig $config = \Ritmo\Domain\Write\QuotaConfig::__set_state(array(
   'perListing' => 3,
   'windowHours' => 24,
   'perAccountPerMinute' => 10,
   'accountWaitAfter429Seconds' => 60,
   'futureSlackSeconds' => 300,
   'failureCooldownMinutes' => 60,
)), int $callTimeoutSeconds = 120);
public function verdict(string $listing, DateTimeImmutable $runStart, ?DateTimeImmutable $nextFullRun = null): QuotaVerdict;
public function runStart(DateTimeImmutable $slotStart, bool $scheduled): DateTimeImmutable;
public function attempt(string $listing, Closure $call): void;
```

#### `Ritmo\Application\Write\Quota\RecalcQuota` (final class)

```php
public function __construct(QuotaConfig $config = \Ritmo\Domain\Write\QuotaConfig::__set_state(array(
   'perListing' => 3,
   'windowHours' => 24,
   'perAccountPerMinute' => 10,
   'accountWaitAfter429Seconds' => 60,
   'futureSlackSeconds' => 300,
   'failureCooldownMinutes' => 60,
)), int $pendingTimeoutSeconds = 120);
public function verdict(array $attempts, string $listing, DateTimeImmutable $now, DateTimeImmutable $runStart, ?DateTimeImmutable $nextFullRun = null): QuotaVerdict;
public function runStartFor(DateTimeImmutable $slotStart, DateTimeImmutable $now, bool $scheduled): DateTimeImmutable;
```

#### `Ritmo\Application\Write\Quota\RollingWindow` (final class)

```php
public static function opensAt(array $instants, int $limit, int $windowSeconds): ?DateTimeImmutable;
```

#### `Ritmo\Application\Write\Resume\BrokenGroup` (final class)

```php
const REASON = 'repair_group';
public readonly string $groupKey;
public readonly string $roomCode;
public readonly string $listing;
public readonly array $rewrite;
public function __construct(string $groupKey, string $roomCode, string $listing, array $rewrite);
public function toArray(): array;
public static function fromArray(array $data): self;
public function repairItems(RoomType $room, string $today): array;
```

#### `Ritmo\Application\Write\Resume\NightClass` (enum)

```php
case Applied = 'applied';
case NotApplied = 'not_applied';
case Diverged = 'diverged';
```

#### `Ritmo\Application\Write\Resume\ResumePlanner` (final class)

```php
public function classify(array $rows, StoredState $fresh): ResumeReport;
public static function targets(array $rows): array;
```

#### `Ritmo\Application\Write\Resume\ResumeReport` (final class)

```php
public readonly array $rows;
public readonly array $nights;
public readonly array $broken;
public readonly array $journal;
public readonly array $kept;
public readonly array $observed;
public function __construct(array $rows, array $nights, array $broken, array $journal = [], array $kept = [], array $observed = []);
public function keeping(array $kept): self;
public function recovered(): array;
public function ownUnobserved(): array;
public function applied(): array;
```

#### `Ritmo\Application\Write\Resume\ResumeService` (final class)

```php
public function __construct(WriteOpRepository $ops);
public function resume(int $runId, string $propertyCode, StateReader $reader): ResumeReport;
```

#### `Ritmo\Application\Write\Schedule\CalendarChange` (final class)

```php
public readonly DateTimeImmutable $from;
public readonly DateTimeImmutable $to;
public readonly string $roomCode;
public readonly string $roomId;
public readonly ?int $minStay;
public readonly ?int $maxStay;
public readonly ?Restriction $restriction;
public readonly ?int $available;
public readonly array $channels;
public readonly ?int $minStayBefore;
public readonly bool $alsoPricing;
public readonly string $reason;
public readonly ?int $expectedAvailable;
public function __construct(string $roomCode, string $roomId, DateTimeImmutable $from, DateTimeImmutable $to, ?int $minStay = null, ?int $maxStay = null, ?Restriction $restriction = null, ?int $available = null, array $channels = [], ?int $minStayBefore = null, bool $alsoPricing = false, string $reason = '', ?int $expectedAvailable = null);
public static function fromStay(array $changes, array $rooms): array;
public function range(): array;
public function nights(): array;
```

#### `Ritmo\Application\Write\Schedule\WriteSchedule` (final class)

```php
public function __construct(WriteConfig $config = \Ritmo\Domain\Write\WriteConfig::__set_state(array(
   'maxReadAgeMinutes' => 30,
   'maxValidationAgeMinutes' => 10,
   'tokenTtlMinutes' => 15,
   'horizonDays' => 365,
   'horizonEdgeDays' => 2,
   'maxIdenticalRefusals' => 2,
   'listingChangesEnabled' => false,
   'pmsMaxRangesPerPost' => 100,
   'callTimeoutSeconds' => 120,
   'maxDatesPerCall' => 70,
)));
public function build(array $slices, DateTimeImmutable $today, array $pms = [], array $recalculate = []): array;
```

#### `Ritmo\Application\Write\Schedule\WriteStep` (final class)

```php
public readonly int $number;
public readonly WriteOperation $operation;
public readonly string $roomCode;
public readonly array $nights;
public readonly array $payload;
public readonly string $label;
public readonly string $groupKey;
public readonly array $changes;
public function __construct(int $number, WriteOperation $operation, string $roomCode, array $nights, array $payload, string $label, string $groupKey = '', array $changes = []);
public function at(int $number): self;
public function request(): WriteRequest;
public function target(): string;
public function dates(): array;
public function isGroupRewrite(): bool;
public function fields(): array;
```

#### `Ritmo\WordPress\Activator` (final class)

```php
public static function activate(): void;
```

#### `Ritmo\WordPress\ConnectorFactory` (final class implements ConnectorSource)

```php
const BEDS24_SECRET = 'beds24_token';
const PRICELABS_SECRET = 'pricelabs_api_key';
const BEDS24_TOKEN_KIND = 'beds24_token_kind';
const BEDS24_PROPERTY_IDS = 'beds24_property_ids';
const PRICELABS_PMS = 'pricelabs_pms';
const DEFAULT_PRICELABS_PMS = 'beds24';
const CALENDAR_READ_CONSTANT = 'RITMO_PRICING_CALENDAR_READ';
const BEDS24_URL_CONSTANT = 'RITMO_BEDS24_BASE_URL';
const PRICELABS_URL_CONSTANT = 'RITMO_PRICELABS_BASE_URL';
public function __construct(SodiumVault $vault, KeyValueStore $settings, RoomTypeRepository $roomTypes, QuotaLogRepository $quotaLog, HttpClient $wire, Clock $clock, Logger $logger, LogSanitizer $sanitizer, ?WriteAuthority $authority = null);
public function pms(): PmsConnector;
public function pricing(): PricingConnector;
public function readsPricingCalendar(): bool;
public function test(string $provider): ConnectionStatus;
public static function propertyIds(string $text): array;
public function pricingPmsName(): string;
public function pricingStaySync(): StaySyncCapability;
```

#### `Ritmo\WordPress\Deactivator` (final class)

```php
public static function deactivate(): void;
```

#### `Ritmo\WordPress\Roles` (final class)

```php
const VIEW = 'ritmo_view';
const MANAGE_RULES = 'ritmo_manage_rules';
const APPROVE = 'ritmo_approve';
const MANAGE_CONNECTIONS = 'ritmo_manage_connections';
const MANAGE_SAFETY = 'ritmo_manage_safety';
const ROLE = 'ritmo_revenue_manager';
const VIEWER_ROLE = 'ritmo_revenue_viewer';
const CAPABILITIES = ['ritmo_view', 'ritmo_manage_rules', 'ritmo_approve', 'ritmo_manage_connections', 'ritmo_manage_safety'];
const GRANTS = [[['ritmo_view', 'ritmo_manage_rules', 'ritmo_approve', 'ritmo_manage_connections'], ['ritmo_view', 'ritmo_manage_rules', 'ritmo_approve', 'ritmo_manage_connections'], []], [[], ['ritmo_manage_safety'], ['ritmo_view']]];
const VERSION_KEY = 'roles_version';
public static function install(KeyValueStore $settings): void;
public static function pending(int $done, bool $managerExists, bool $viewerExists = false): array;
public static function maySwitchEmergency(bool $engage): bool;
public static function remove(): void;
```

#### `Ritmo\WordPress\Scheduler` (final class)

```php
const LEGACY_HOOK = 'ritmo_resume_shadow_runs';
const NOW = 'now';
public static function schedule(): void;
public static function unschedule(): void;
public static function tickNow(): void;
public static function state(): array;
```

#### `Ritmo\WordPress\Services` (final class)

```php
public static function instance(): self;
public function db(): WpdbDatabase;
public function clock(): Clock;
public function store(): OptionsStore;
public function sanitizer(): LogSanitizer;
public function logger(): DbLogger;
public function vault(): SodiumVault;
public function migrator(): Migrator;
public function properties(): PropertyRepository;
public function roomTypes(): RoomTypeRepository;
public function ruleSets(): RuleSetRepository;
public function runs(): RunRepository;
public function planItems(): PlanItemRepository;
public function alerts(): AlertRepository;
public function snapshots(): InventorySnapshotRepository;
public function locks(): DbLock;
public function emergencySwitch(): EmergencySwitch;
public function gate(): WriteGate;
public function connectors(): ConnectorFactory;
public function stack(): RunStack;
public function shadowRuns(): ShadowRunService;
public function occupancy(): OccupancyQuery;
public function setup(): SetupService;
```

#### `Ritmo\WordPress\TimeLimit` (final class)

```php
const WRITE_SECONDS = 300;
public static function forWrite(): ?int;
```

#### `Ritmo\WordPress\Uninstaller` (final class)

```php
const DELETE_DATA = 'delete_data_on_uninstall';
public function __construct(KeyValueStore $settings, Closure $removeData);
public static function forSite(): self;
public function run(): bool;
```

#### `Ritmo\WordPress\WpCapabilityCheck` (final class implements CapabilityCheck)

```php
public function userCan(int $userId, string $capability): bool;
```

#### `Ritmo\Admin\AdminScreens` (final class)

```php
public static function register(): void;
public static function renderers(): array;
public static function actions(): array;
public static function registerActions(): void;
public static function enqueue(string $hook): void;
public static function vaultNotice(): void;
```

#### `Ritmo\Admin\Components\Capability` (final class)

```php
const VIEW = 'ritmo_view';
const MANAGE_RULES = 'ritmo_manage_rules';
const APPROVE = 'ritmo_approve';
const MANAGE_CONNECTIONS = 'ritmo_manage_connections';
const MANAGE_SAFETY = 'ritmo_manage_safety';
const ALL = ['ritmo_view', 'ritmo_manage_rules', 'ritmo_approve', 'ritmo_manage_connections', 'ritmo_manage_safety'];
public static function can(string $capability): bool;
```

#### `Ritmo\Admin\Components\ConfirmTyped` (final class)

```php
public static function check(string $expected, string $typed): void;
```

#### `Ritmo\Admin\Components\CsvWriter` (final class)

```php
public static function send(string $filename, array $header, iterable $rows): void;
public static function write($stream, array $header, iterable $rows): void;
public static function cell(string|int|float|null $value): string;
public static function filename(string $name): string;
```

#### `Ritmo\Admin\Components\DomainText` (final class)

```php
public static function t(string $english): string;
```

#### `Ritmo\Admin\Components\ErrorText` (final class)

```php
public static function from(Throwable $failure, ?callable $log = null): string;
public static function reference(Throwable $failure): string;
```

#### `Ritmo\Admin\Components\FormAction` (final class)

```php
public readonly string $action;
public readonly string $capability;
public readonly string $page;
public readonly Closure $handler;
public readonly bool $perObjectNonce;
public readonly string $objectField;
public readonly bool $download;
public function __construct(string $action, string $capability, string $page, Closure $handler, bool $perObjectNonce = false, string $objectField = '', bool $download = false);
public function nonceAction(string $objectId = ''): string;
```

#### `Ritmo\Admin\Components\Input` (final class)

```php
public function __construct(array $fields);
public static function query(): self;
public static function form(): self;
public function has(string $name): bool;
public function text(string $name, int $max = 190): string;
public function multiline(string $name, int $max = 1000): string;
public function secret(string $name): string;
public function int(string $name, int $default = 0, int $min = -9223372036854775807-1, int $max = 9223372036854775807): int;
public function date(string $name): ?DateTimeImmutable;
public function choice(string $name, array $allowed, string $default): string;
public function bool(string $name): bool;
public function list(string $name, string $pattern, int $max = 5000): array;
public function rows(string $name, array $fields, int $max = 100): array;
```

#### `Ritmo\Admin\Components\Labels` (final class)

```php
public static function mode(string $mode): string;
public static function modeSentence(string $mode): string;
public static function runKind(string $kind): string;
public static function runStatus(string $status): string;
public static function runStage(string $stage): string;
public static function pace(?string $status): string;
public static function paceSymbol(?string $status): string;
public static function severity(string $severity): string;
public static function alertStatus(string $status): string;
public static function approvalStatus(string $status): string;
public static function approvalKind(string $kind): string;
public static function provider(string $provider): string;
public static function field(string $field): string;
public static function nightType(?string $type): string;
```

#### `Ritmo\Admin\Components\Menu` (final class)

```php
const TOP = 'ritmo-revenue';
const OVERVIEW = 'ritmo-revenue';
const NIGHTS = 'ritmo-revenue-nights';
const APPROVALS = 'ritmo-revenue-approvals';
const ALERTS = 'ritmo-revenue-alerts';
const TO_DO = 'ritmo-revenue-todo';
const CHANGES = 'ritmo-revenue-changes';
const REPORTS = 'ritmo-revenue-reports';
const MARKET = 'ritmo-revenue-market';
const ORDERS = 'ritmo-revenue-orders';
const RULES = 'ritmo-revenue-rules';
const OPERATIONS = 'ritmo-revenue-operations';
const SETTINGS = 'ritmo-revenue-settings';
const REDIRECTS = [['ritmo-revenue-settings', 'connections'], ['ritmo-revenue-settings', 'setup'], ['ritmo-revenue-settings', 'diagnostics']];
public static function entries(): array;
public static function register(array $renderers, array $counters = []): void;
public static function counter(int $count): string;
public static function redirectOldSlugs(): void;
public static function url(string $slug, array $args = []): string;
```

#### `Ritmo\Admin\Components\Money` (final class)

```php
public static function format(float $amount, string $currency): string;
public static function symbol(string $currency): string;
```

#### `Ritmo\Admin\Components\NightDate` (final class)

```php
public static function short(DateTimeInterface $night): string;
public static function long(DateTimeInterface $night): string;
public static function weekday(DateTimeInterface $night): string;
public static function day(DateTimeInterface $night): string;
public static function range(DateTimeInterface $first, DateTimeInterface $last): string;
public static function instant(DateTimeInterface $instant, string $timezone): string;
public static function time(DateTimeInterface $instant, string $timezone): string;
public static function relative(DateTimeInterface $instant, DateTimeImmutable $now): string;
```

#### `Ritmo\Admin\Components\Paging` (final class)

```php
public readonly int $page;
public readonly int $perPage;
public readonly int $total;
public function __construct(int $page, int $perPage, int $total);
public function offset(): int;
public function pages(): int;
public function hasPrevious(): bool;
public function hasNext(): bool;
public function label(): string;
```

#### `Ritmo\Admin\Components\Percent` (final class)

```php
public static function change(float $before, float $after): string;
public static function ofBase(int $percent): string;
public static function share(?float $percent): string;
```

#### `Ritmo\Admin\Components\PriceText` (final class)

```php
public static function amount(?float $amount, string $currency): array;
public static function withBase(float $amount, int $percent, float $base, string $currency): array;
public static function fromPercent(?int $percent, float $base, string $currency): array;
public static function change(?float $before, ?float $after, string $currency): array;
```

#### `Ritmo\Admin\Components\PrivacyGuard` (final class)

```php
public static function check(string $text): ?string;
public static function looksPrivate(string $text): bool;
```

#### `Ritmo\Admin\Components\Ratio` (final class)

```php
public static function fromPercentText(string $text): float;
public static function toPercentText(float $ratio): string;
```

#### `Ritmo\Admin\Components\Screen` (final class)

```php
public readonly string $slug;
public readonly string $title;
public readonly string $capability;
public readonly array $tabs;
public readonly Closure $render;
public function __construct(string $slug, string $title, string $capability, array $tabs, Closure $render);
public function tab(string $asked): ?Tab;
```

#### `Ritmo\Admin\Components\Tab` (final class)

```php
public readonly string $id;
public readonly string $title;
public readonly string $capability;
public function __construct(string $id, string $title, string $capability);
```

#### `Ritmo\Admin\Components\UserFacing` (final class extends RuntimeException)

```php
// no public members
```

#### `Ritmo\Admin\Editors\CalendarActions` (final class)

```php
public static function calendar(Input $form): array;
public static function holidays(Input $form): array;
```

#### `Ritmo\Admin\Editors\CalendarView` (final class)

```php
public static function of(EditorPage $page, array $rules, DateTimeImmutable $firstMonth, ?array $import, bool $readOnly): array;
```

#### `Ritmo\Admin\Editors\ChoiceLabels` (final class)

```php
public static function of(string $key, string|int $value): string;
```

#### `Ritmo\Admin\Editors\DraftDesk` (final class)

```php
public function __construct(RuleDraftStorage $storage, RuleSetRepository $ruleSets, Clock $clock);
public function current(int $userId): ?RuleDraft;
public function rules(int $userId): array;
public function inForce(): array;
public function stage(int $userId, array $rules, array $touched, string $source = 'editor', ?int $restoredFrom = null): void;
public function discard(int $userId): void;
public function changes(int $userId): int;
```

#### `Ritmo\Admin\Editors\EditorPage` (final class)

```php
const USER_META = 'ritmo_property';
public readonly string $slug;
public readonly string $title;
public readonly array $properties;
public readonly ?Property $property;
public readonly array $tabs;
public readonly string $tab;
public static function open(string $slug, string $title, string $capability, array $tabs): self;
public static function require(string $capability): void;
public static function forbid(): never;
public function url(array $args = []): string;
public function roomNames(): array;
public function rooms(): array;
public function currency(): string;
public function render(string $template, array $view): void;
```

#### `Ritmo\Admin\Editors\EditorScreens` (final class)

```php
const ASSETS = ['editors'];
public static function screens(): array;
public static function actions(): array;
```

#### `Ritmo\Admin\Editors\EditorServices` (final class)

```php
public static function instance(): self;
public function services(): Services;
public function editor(): RuleSetEditor;
public function checks(): RuleChecks;
public function history(): VersionHistory;
public function drafts(): DraftDesk;
public function orders(): OrderDesk;
public function orderBoard(): OrderBoard;
public function holidays(): HolidayCatalogue;
public function events(): EditorEventRepository;
```

#### `Ritmo\Admin\Editors\EditorText` (final class)

```php
public static function refusal(RulesRefused $refused): string;
public static function privacy(string $problem): string;
public static function unreadable(string $code): string;
public static function validation(ValidationError $error, Schema $schema, array $rules): string;
public static function problem(ValidationError $error): string;
```

#### `Ritmo\Admin\Editors\FieldContext` (final class)

```php
public readonly Schema $schema;
public readonly ?FormScope $scope;
public readonly array $inForce;
public readonly array $rooms;
public readonly string $currency;
public readonly array $errors;
public readonly bool $readOnly;
public readonly string $nameRoot;
public function __construct(Schema $schema, ?FormScope $scope, array $inForce, array $rooms, string $currency, array $errors = [], bool $readOnly = false, string $nameRoot = 'f');
public function holds(string $key): bool;
```

#### `Ritmo\Admin\Editors\FieldPath` (final class)

```php
const SEPARATOR = ' › ';
public static function label(string $path, Schema $schema, array $rules): string;
public static function anchor(string $path): string;
```

#### `Ritmo\Admin\Editors\FieldView` (final class)

```php
public static function node(Field $field, string $path, mixed $value, FieldContext $ctx): ?array;
public static function nodes(array $keys, array $rules, FieldContext $ctx): array;
public static function name(string $root, string $path): string;
```

#### `Ritmo\Admin\Editors\FieldWidget` (final class)

```php
const NUMBER = 'number';
const PERCENT = 'percent';
const CHECKBOX = 'checkbox';
const TEXT = 'text';
const TIME = 'time';
const SELECT = 'select';
const DATE = 'date';
const WEEKDAYS = 'weekdays';
const CHOICES = 'choices';
const LINES = 'lines';
const GROUP = 'group';
const ITEMS = 'items';
const MAP = 'map';
const VARIANT = 'variant';
public static function kind(Field $field, Schema $schema): string;
public static function display(Field $field, mixed $value): string;
public static function read(Field $field, mixed $posted): mixed;
public static function bounds(Field $field): array;
```

#### `Ritmo\Admin\Editors\FormErrors` (final class)

```php
public static function flash(array $byPath): void;
public static function take(): array;
```

#### `Ritmo\Admin\Editors\FormResult` (final class)

```php
public readonly array $rules;
public readonly array $errors;
public readonly array $touched;
public function __construct(array $rules, array $errors, array $touched);
```

#### `Ritmo\Admin\Editors\FormScope` (final class)

```php
public readonly string $tab;
public readonly string $section;
public static function section(string $section): self;
public static function tab(string $tab): self;
public function roots(): array;
public function holds(string $key): bool;
public function id(): string;
```

#### `Ritmo\Admin\Editors\OrderText` (final class)

```php
public static function describe(OwnerOrder $order, array $roomNames, string $currency): string;
public static function type(OrderType $type): string;
public static function release(OrderRelease $release): string;
public static function rooms(OwnerOrder $order, array $roomNames): string;
```

#### `Ritmo\Admin\Editors\OrdersActions` (final class)

```php
public static function actions(): array;
```

#### `Ritmo\Admin\Editors\OrdersScreen` (final class)

```php
public static function tabs(): array;
public static function render(): void;
```

#### `Ritmo\Admin\Editors\Placement` (final class)

```php
public readonly string $tab;
public readonly string $section;
public readonly bool $advanced;
public function __construct(string $tab, string $section, bool $advanced);
```

#### `Ritmo\Admin\Editors\PresetFile` (final class)

```php
const MAX_BYTES = 1048576;
public static function send(): void;
public static function upload(string $field): string;
```

#### `Ritmo\Admin\Editors\RelationActions` (final class)

```php
public static function relation(Input $form): array;
public static function band(Input $form): array;
```

#### `Ritmo\Admin\Editors\RelationCycle` (final class)

```php
public static function find(array $relations, array $rooms): ?array;
```

#### `Ritmo\Admin\Editors\RelationForm` (final class)

```php
const HIDDEN = ['id', 'property', 'type'];
public static function relationSchema(RelationType $type): Schema;
public static function bandSchema(): Schema;
public static function typed(Schema $schema): array;
public static function newId(string $prefix, string $room, array $taken): string;
```

#### `Ritmo\Admin\Editors\RelationText` (final class)

```php
public static function describe(array $relation, array $roomNames, string $currency): string;
public static function loop(array $walk, array $labels, array $roomNames): string;
public static function set(array $set, array $roomNames): string;
```

#### `Ritmo\Admin\Editors\RelationsView` (final class)

```php
public static function of(EditorPage $page, array $rules, string $add, string $editId, string $bandId, bool $mayEdit): array;
```

#### `Ritmo\Admin\Editors\RequestFields` (final class)

```php
public static function posted(string $name): array;
```

#### `Ritmo\Admin\Editors\ReviewView` (final class)

```php
public static function of(RuleDraft $draft, EditorPage $page, bool $maySafety): array;
public static function fieldUrl(EditorPage $page, string $path): string;
```

#### `Ritmo\Admin\Editors\RuleFormReader` (final class)

```php
const PRESENT = '__present';
const NEW_ITEM = '__new';
const REMOVE = '_remove';
const ADD = '_add';
public static function read(Schema $schema, ?FormScope $scope, array $rules, array $posted): FormResult;
```

#### `Ritmo\Admin\Editors\RulesActions` (final class)

```php
public static function actions(): array;
public static function at(array $data, array $segments): mixed;
public static function put(array $data, array $segments, mixed $value): array;
```

#### `Ritmo\Admin\Editors\RulesLayout` (final class)

```php
const GENERAL = 'general';
const SAFETY = 'safety';
const RELATIONS = 'relations';
const CALENDAR = 'calendar';
const SECTIONS = [['occupancy'], ['pace'], ['floors', 'price_factors'], ['ladder'], ['market'], ['soldout', 'cadence', 'no_effect'], ['stay'], ['orders'], ['plan', 'write', 'verify', 'approval', 'third_party', 'waves'], ['schedule', 'light_run', 'alerts', 'modes'], ['reports']];
public static function place(string $key): Placement;
public static function advanced(string $key): bool;
public static function roots(string $section): array;
public static function under(string $key, string $prefix): bool;
```

#### `Ritmo\Admin\Editors\RulesScreen` (final class)

```php
public static function tabs(): array;
public static function sections(): array;
public static function render(): void;
```

#### `Ritmo\Admin\Editors\UserMetaDraftStorage` (final class implements RuleDraftStorage)

```php
const META = 'ritmo_rules_draft';
public function get(int $userId): ?RuleDraft;
public function put(int $userId, RuleDraft $draft): void;
public function clear(int $userId): void;
```

#### `Ritmo\Admin\Editors\ValueText` (final class)

```php
public static function of(?string $key, mixed $value, Schema $schema, string $currency): string;
public static function weekday(int $day): string;
public static function weekdayShort(int $day): string;
```

#### `Ritmo\Admin\Editors\VersionsView` (final class)

```php
public static function of(EditorPage $page, int $pageNumber, ?int $from, ?int $to): array;
public static function person(int $userId): string;
```

#### `Ritmo\Admin\Market\CsvUploadReader` (final class)

```php
public function __construct(Closure $isUploaded, Closure $isCsv);
public function read(array $file, int $maxBytes, int $maxRows): UploadedFile;
public static function cleanName(string $name): string;
```

#### `Ritmo\Admin\Market\MarketActions` (final class)

```php
const TEMPLATE = [['comp-01', '2027-01-15', '2027-01-17', '1840.00', 'total', 'cheapest', 'BRL', '2027-01-02 09:30', 'booking', '1'], ['comp-02', '2027-01-15', '2027-01-17', '1125.50', 'per_night', 'comparable', 'BRL', '2027-01-02', 'booking', '1']];
public static function actions(): array;
```

#### `Ritmo\Admin\Market\MarketScreen` (final class)

```php
const PRICES_SOURCES = ['csv', 'manual', 'booking'];
public static function screens(): array;
public static function tabs(): array;
public static function render(): void;
public static function maxBytes(MarketConfig $config): int;
```

#### `Ritmo\Admin\Market\MarketServices` (final class)

```php
public static function instance(): self;
public function services(): Services;
public function series(): MarketSeriesService;
public function import(): MarketImportService;
public function competitorService(): CompetitorService;
public function board(): MarketBoard;
public function export(): MarketExport;
public function freshness(): MarketFreshness;
public function reminder(): MarketReminder;
public function uploads(): CsvUploadReader;
```

#### `Ritmo\Admin\Market\MarketText` (final class)

```php
public static function problem(MarketCsvProblem $problem): string;
public static function what(string $code): string;
public static function refusal(MarketRefused $refused, int $maxBytes = 0): string;
public static function series(string $name): string;
public static function point(RulerPoint $point): string;
public static function origin(NightValue $value): string;
public static function factor(?float $factor, string $source, float $default, float $cap): string;
```

#### `Ritmo\Admin\Market\TransientPreviewStore` (final class implements PreviewStore)

```php
public function put(string $token, array $payload, int $ttlSeconds): void;
public function get(string $token): ?array;
public function delete(string $token): void;
```

#### `Ritmo\Admin\Notice` (final class)

```php
const SUCCESS = 'success';
const ERROR = 'error';
const WARNING = 'warning';
public static function flash(string $type, string $message, array $details = []): void;
public static function take(): ?array;
```

#### `Ritmo\Admin\ReasonText` (final class)

```php
public static function sentences(array $trace): array;
public static function reason(string $code): string;
public static function describe(array $trace): string;
```

#### `Ritmo\Admin\RefusalText` (final class)

```php
public static function mode(ModeRefused $refused): string;
public static function approval(ApprovalRefused $refused): string;
public static function condition(string $code, array $params): string;
```

#### `Ritmo\Admin\Reports\BarChart` (final class)

```php
const WIDTH = 640;
public static function of(array $bars): ?array;
```

#### `Ritmo\Admin\Reports\Cell` (enum)

```php
case Text = 'text';
case Date = 'date';
case Count = 'count';
case Money = 'money';
case Fraction = 'fraction';
case Percent = 'percent';
case Change = 'change';
public function format(string|int|float|null $value, string $currency): string;
public function csv(string|int|float|null $value): string|int|float|null;
public function isMoney(): bool;
```

#### `Ritmo\Admin\Reports\MonthTables` (final class)

```php
public static function of(MonthReport $r, string $propertyId, array $roomNames): ReportTable;
public static function summary(MonthReport $r, string $propertyId, string $currency): string;
```

#### `Ritmo\Admin\Reports\OccupancyTables` (final class)

```php
public static function windows(array $rows, array $names): ReportTable;
public static function map(array $map, array $roomNames): ReportTable;
```

#### `Ritmo\Admin\Reports\ReportTable` (final class)

```php
public readonly string $id;
public readonly string $caption;
public readonly array $columns;
public readonly array $rows;
public readonly ?array $totals;
public function __construct(string $id, string $caption, array $columns, array $rows, ?array $totals = null);
public function csvHeader(): array;
public function csvRows(string $currency): array;
public function formatted(array $row, string $currency): array;
public function isEmpty(): bool;
```

#### `Ritmo\Admin\Reports\ReportViews` (final class)

```php
const TABS = ['weekly', 'month', 'channels', 'rooms', 'review', 'targets', 'occupancy'];
public static function of(string $tab, ReportService $reports, ReportScope $scope, DateTimeImmutable $now, string $month, array $roomNames): array;
public static function monthOf(string $asked, DateTimeImmutable $today): string;
```

#### `Ritmo\Admin\Reports\ReportsActions` (final class)

```php
public static function actions(): array;
public static function number(string $raw): float|false;
```

#### `Ritmo\Admin\Reports\ReportsScreen` (final class)

```php
public static function screens(): array;
public static function tabs(): array;
public static function render(): void;
```

#### `Ritmo\Admin\Reports\ReportsServices` (final class)

```php
public static function instance(): self;
public function services(): Services;
public function scope(Property $property): ReportScope;
public function reports(): ReportService;
public function targetStore(): RevenueTargetStore;
public static function config(RuleSet $rules): ReportsConfig;
```

#### `Ritmo\Admin\Reports\ReportsText` (final class)

```php
public static function channel(string $channel): string;
public static function reading(?Reading $reading): string;
public static function moment(Moment $moment): string;
public static function scenario(Scenario $scenario): string;
public static function rowKind(RowKind $kind): string;
public static function month(string $month): string;
public static function weekday(int $weekday): string;
```

#### `Ritmo\Admin\Reports\ReviewTables` (final class)

```php
public static function review(ReviewResult $r): ReportTable;
public static function targets(array $forecast, array $targets): ReportTable;
public static function summary(ReviewResult $r): string;
```

#### `Ritmo\Admin\Reports\RunLineText` (final class)

```php
public static function render(array $stored): string;
public static function ofSummary(array $summary): array;
public static function of(RunLine $line): string;
```

#### `Ritmo\Admin\Reports\WeeklyTables` (final class)

```php
public static function made(WeeklyReport $r): ReportTable;
public static function stays(WeeklyReport $r): ReportTable;
public static function goals(WeeklyReport $r): ReportTable;
public static function shares(WeeklyReport $r, bool $channels, array $roomNames): ReportTable;
public static function summary(WeeklyReport $r, string $currency): string;
```

#### `Ritmo\Admin\Reports\WriteReasonText` (final class)

```php
public static function of(string $code): string;
```

#### `Ritmo\Admin\Screens\AlertActions` (final class)

```php
public static function actions(): array;
```

#### `Ritmo\Admin\Screens\AlertText` (final class)

```php
const FACTS = ['room', 'night', 'channel', 'ref', 'provider', 'nights', 'units_over', 'sold', 'pms_sold', 'pricing_sold', 'expected', 'value', 'ratio', 'days', 'from', 'to', 'before', 'after', 'last_pushed_at', 'slot', 'groups_open', 'entries', 'count', 'held', 'until', 'mode', 'stage'];
public static function title(string $code, array $facts = []): string;
public static function explain(string $code): string;
public static function action(string $code): string;
public static function factLabel(string $key): ?string;
public static function codes(): array;
```

#### `Ritmo\Admin\Screens\AlertsScreen` (final class)

```php
const PER_PAGE = 50;
public static function render(): void;
```

#### `Ritmo\Admin\Screens\ApprovalActions` (final class)

```php
const ENTRY_PATTERN = '/^(item|deletion|listing)\\|[A-Za-z0-9_-]{1,32}\\|[A-Za-z0-9_-]{1,40}$/';
const NOTE_MAX = 500;
public static function actions(): array;
public static function plain(?float $amount): string;
```

#### `Ritmo\Admin\Screens\ApprovalsScreen` (final class)

```php
public static function render(): void;
```

#### `Ritmo\Admin\Screens\ChangeActions` (final class)

```php
const EXPORT_ROWS = 5000;
public static function actions(): array;
```

#### `Ritmo\Admin\Screens\ChangesScreen` (final class)

```php
const PER_PAGE = 50;
public static function render(): void;
public static function filter(Input $input, string $propertyId): ChangeFilter;
```

#### `Ritmo\Admin\Screens\ConnectionActions` (final class)

```php
const PROVIDERS = ['beds24_token', 'pricelabs_api_key'];
public static function actions(): array;
public static function run(string $provider): array;
```

#### `Ritmo\Admin\Screens\ConnectionText` (final class)

```php
public static function provider(string $provider): string;
public static function result(string $provider, string $reason, array $counts = []): string;
```

#### `Ritmo\Admin\Screens\ConnectionsView` (final class)

```php
public static function build(): array;
```

#### `Ritmo\Admin\Screens\DiagnosticsActions` (final class)

```php
public static function actions(): array;
```

#### `Ritmo\Admin\Screens\DiagnosticsView` (final class)

```php
const LOG_ROWS = 100;
public static function build(Page $page): array;
public static function context(array $context): string;
public static function levelLabel(string $level): string;
```

#### `Ritmo\Admin\Screens\NightActions` (final class)

```php
public static function actions(): array;
```

#### `Ritmo\Admin\Screens\NightDetailView` (final class)

```php
public static function build(Property $property, string $room, DateTimeImmutable $night, Page $page): array;
```

#### `Ritmo\Admin\Screens\NightText` (final class)

```php
const SHOW = ['price', 'floor', 'ceiling', 'stay', 'occupancy', 'pace', 'rule'];
public static function reason(string $code): string;
public static function visible(NightCell $cell, string $show, string $currency): string;
public static function accessible(NightCell $cell, string $roomName, string $currency): string;
public static function status(PlannedNight $planned): string;
public static function bound(?float $amount, ?int $percent, float $base, string $currency): array;
```

#### `Ritmo\Admin\Screens\NightsScreen` (final class)

```php
const WINDOWS = [7, 14, 30, 60, 90];
public static function render(): void;
```

#### `Ritmo\Admin\Screens\OperationsActions` (final class)

```php
public static function actions(): array;
public static function maySwitch(bool $engage): bool;
```

#### `Ritmo\Admin\Screens\OperationsScreen` (final class)

```php
public static function tabs(): array;
public static function render(): void;
```

#### `Ritmo\Admin\Screens\OperationsText` (final class)

```php
public static function event(array $event): string;
public static function actor(string $actor): string;
```

#### `Ritmo\Admin\Screens\OverviewScreen` (final class)

```php
public static function render(): void;
```

#### `Ritmo\Admin\Screens\Page` (final class)

```php
const USER_META = 'ritmo_property';
public readonly string $slug;
public readonly string $title;
public readonly array $properties;
public readonly ?Property $property;
public readonly array $tabs;
public readonly string $tab;
public static function open(string $slug, string $title, string $capability, array $tabs = []): self;
public static function require(string $capability): void;
public static function forbid(): never;
public function render(string $template, array $view): void;
public function url(array $args = []): string;
public static function headerOf(string $slug, string $title, array $properties, ?Property $property, array $tabs, string $tab): array;
```

#### `Ritmo\Admin\Screens\PeopleNames` (final class)

```php
public static function name(int $userId): string;
```

#### `Ritmo\Admin\Screens\PmsChangeText` (final class)

```php
public static function lines(array $fields): array;
```

#### `Ritmo\Admin\Screens\ScreenActions` (final class)

```php
public static function register(array $actions): void;
public static function handle(FormAction $action): void;
```

#### `Ritmo\Admin\Screens\ScreenServices` (final class)

```php
public static function instance(): self;
public function services(): Services;
public function lastPlan(): LastPlan;
public function grid(): NightGridQuery;
public function nightDetail(): NightDetailQuery;
public function approvals(): ApprovalBoard;
public function status(): StatusQuery;
public function alerts(): AlertList;
public function changes(): ChangeList;
public function todoStore(): TodoStore;
public function todos(): TodoService;
public function todoGenerator(): TodoGenerator;
public function overview(): OverviewQuery;
public function connectionTests(): ConnectionTests;
public function setupState(): SetupState;
public function wizard(): SetupWizard;
public function listingSuggestions(): ListingSuggestions;
public function wouldDo(): WouldDo;
```

#### `Ritmo\Admin\Screens\Screens` (final class)

```php
public static function renderers(): array;
public static function counters(): array;
public static function actions(): array;
public static function enqueue(string $hook): void;
```

#### `Ritmo\Admin\Screens\SettingsActions` (final class)

```php
public static function actions(): array;
```

#### `Ritmo\Admin\Screens\SettingsScreen` (final class)

```php
public static function render(): void;
```

#### `Ritmo\Admin\Screens\TodoActions` (final class)

```php
public static function actions(): array;
```

#### `Ritmo\Admin\Screens\TodoScreen` (final class)

```php
public static function render(): void;
```

#### `Ritmo\Admin\Screens\TodoText` (final class)

```php
public static function title(TodoItem $item): string;
public static function why(TodoItem $item): string;
public static function where(TodoItem $item, string $propertyId): ?string;
```

#### `Ritmo\Admin\Screens\UndoNow` (final class implements UndoRequests)

```php
public function __construct(UndoWriter $writer, RuleSet $rules);
public function request(string $propertyId, array $changeIds, int $userId): array;
```

#### `Ritmo\Admin\Screens\UndoText` (final class)

```php
public static function reason(string $code): string;
public static function status(string $status): string;
```

#### `Ritmo\Admin\Screens\WarningText` (final class)

```php
public static function short(string $code, EntryRow $entry): string;
public static function long(string $code, EntryRow $entry): string;
```

#### `Ritmo\Admin\Screens\WizardActions` (final class)

```php
public static function actions(): array;
```

#### `Ritmo\Admin\Screens\WizardView` (final class)

```php
public static function build(Page $page): array;
```

#### `Ritmo\Admin\View` (final class)

```php
public static function render(string $template, array $view): void;
```

#### `Ritmo\Rest\Editors\EditorEndpoints` (final class)

```php
public function __construct(VersionHistory $history, Closure $person, Closure $instant, string $currency);
public function versions(int $page): array;
public function compare(int $from, int $to): array;
```

#### `Ritmo\Rest\Editors\EditorRoutes` (final class)

```php
public static function register(): void;
```

#### `Ritmo\Rest\Market\MarketEndpoints` (final class)

```php
const RULER_MAX_NIGHTS = 120;
public function __construct(PropertyRepository $properties, CompetitorService $competitors, MarketBoard $board, MarketImportService $import, Closure $rules, Clock $clock);
public function competitors(string $propertyId): array;
public function saveCompetitor(string $propertyId, ?int $id, array $fields, int $userId): array;
public function preview(string $propertyId, Closure $upload, int $userId, int $maxBytes): array;
public function commit(string $propertyId, string $token, int $userId): array;
public function ruler(string $propertyId, ?DateTimeImmutable $from, ?DateTimeImmutable $to): array;
public function imports(string $propertyId): array;
```

#### `Ritmo\Rest\Market\MarketRoutes` (final class)

```php
public static function register(): void;
```

#### `Ritmo\Rest\OperationInput` (final class)

```php
const APPROVE = 'approve';
const REJECT = 'reject';
public static function decision(mixed $value): string;
public static function keys(mixed $value): ?array;
public static function engaged(mixed $value): bool;
public static function switchPosition(string $value): bool;
```

#### `Ritmo\Rest\OperationRoutes` (final class)

```php
public static function register(): void;
public static function runNow(WP_REST_Request $request): WP_REST_Response|WP_Error;
public static function mode(WP_REST_Request $request): WP_REST_Response|WP_Error;
public static function approvals(WP_REST_Request $request): WP_REST_Response|WP_Error;
public static function decide(WP_REST_Request $request): WP_REST_Response|WP_Error;
public static function changes(WP_REST_Request $request): WP_REST_Response|WP_Error;
public static function undo(WP_REST_Request $request): WP_REST_Response|WP_Error;
public static function emergency(WP_REST_Request $request): WP_REST_Response|WP_Error;
```

#### `Ritmo\Rest\Reports\ReportEndpoints` (final class)

```php
public function __construct(ReportService $reports, RevenueTargetStore $targets, Closure $property, Closure $scope, Closure $run, DateTimeImmutable $now);
public function report(string $tab, string $propertyId, string $month = ''): array;
public function snapshot(string $propertyId, int $userId): array;
public function saveTargets(string $propertyId, string $month, array $fields, int $userId): array;
public function runReport(int $runId): array;
```

#### `Ritmo\Rest\Reports\ReportRoutes` (final class)

```php
public static function register(): void;
```

#### `Ritmo\Rest\RestRoutes` (final class)

```php
const NAMESPACE = 'ritmo/v1';
public static function register(): void;
public static function allowed(string $capability): callable;
public static function status(): WP_REST_Response;
public static function occupancy(WP_REST_Request $request): WP_REST_Response|WP_Error;
public static function shadowRun(WP_REST_Request $request): WP_REST_Response|WP_Error;
public static function run(WP_REST_Request $request): WP_REST_Response|WP_Error;
```

#### `Ritmo\Rest\Screens\ScreenEndpoints` (final class)

```php
const ENTRY_PATTERN = '/^(item|deletion|listing)\\|[A-Za-z0-9_-]{1,32}\\|[A-Za-z0-9_-]{1,40}$/';
public function __construct(ApprovalBoard $board, AlertService $alerts, Closure $test, Closure $statusText);
public function approvalStatus(int $id): array;
public function selection(int $id, string $property, mixed $keys): array;
public function connectionTest(string $provider): array;
public function acknowledge(int $id, int $userId, bool $isApprover): array;
public function resolve(int $id, int $userId, bool $isApprover): array;
```

#### `Ritmo\Rest\Screens\ScreenRoutes` (final class)

```php
public static function register(): void;
```

<!-- END GENERATED SIGNATURES -->
