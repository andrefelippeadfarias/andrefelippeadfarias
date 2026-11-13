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

| Screen (menu "Ritmo Revenue") | Capability | Content |
|---|---|---|
| Dashboard | `ritmo_view` | traffic light, rooms x windows, alerts, push state, last shadow run, "what would be written" with the reason in words (`Admin\ReasonText`), "Run shadow now" (`ritmo_manage_rules`) |
| Connections | `ritmo_manage_connections` | Beds24 and PriceLabs credentials (write-only), "Test connection" |
| Setup | `ritmo_manage_rules` | properties and their mapping, import preset, competitor prices (CSV) |
| Operations | `ritmo_view` | mode (change form; autonomous with the typed confirmation), "Run now" (`ritmo_manage_rules`), the last report, the emergency switch (`ritmo_manage_connections`), the plan waiting for approval (approve all, approve the nights ticked, refuse: `ritmo_approve`), the change log with "undo" (`ritmo_approve`), who did what |
| Diagnostics | `ritmo_view` | versions, WP-Cron and run locks, last runs, emergency switch and uninstall choice (`ritmo_manage_connections`), recent log |

The role `ritmo_revenue_manager` ("Revenue Manager") has `read` and the four capabilities; administrators get the four. Every form posts to admin-post.php (`Admin\AdminActions`): the capability and the nonce (`check_admin_referer`) are checked before any field is read, values are sanitised and validated by the use case, and the action redirects back with a notice. Every value a template prints goes through `esc_html`, `esc_attr` or `esc_url` as a whole; a source test fails otherwise.

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
| `POST /emergency` | `ritmo_manage_connections` | `engaged` (required boolean): engages or releases the switch (recorded when it moved); 400 without a clear value (a malformed request never releases it) |

The first four are `Rest\RestRoutes`; the operation routes are `Rest\OperationRoutes`.

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
| `VerifyStage`, `JournalNights`, `ReportStage` | Confirmation, record and report. |
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
| `OccupancyQuery`, `OccupancySignal`, `PropertyDashboard`, `SetupService`, `SampleCsv` | Dashboard and setup (1.3). |
| `Services`, `ConnectorFactory`, `WpCapabilityCheck`, `Activator`, `Deactivator`, `Uninstaller`, `Roles`, `Scheduler`, `TimeLimit` | WordPress wiring and lifecycle (1.2). |
| `AdminPages`, `AdminActions`, `View`, `Notice`, `ReasonText`, `RefusalText` | Screens and form actions (1.5); the refusals through the text domain. |
| `RestRoutes`, `OperationRoutes`, `OperationInput` | The REST API (1.6); the strict reading of decisions and of the switch. |

### 1.9 Not in this milestone

The full approval screen, the nights grid, rule and order editors, the PMS stay and arrival changes of the stay audit as writes (the audit raises its alerts; the write path accepts `CalendarChange` but the run passes none yet), the `pt_BR` translation, licence and assistant.

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
public static function of(Plan $plan, array $bases): array;
public static function hash(array $entries): string;
```

#### `Ritmo\Application\Approval\PlanEntry` (final class)

```php
const ITEM = 'item';
const DELETION = 'deletion';
const LISTING = 'listing';
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

#### `Ritmo\Application\Dashboard\PropertyDashboard` (final class)

```php
const WOULD_WRITE_SHOWN = 60;
public function __construct(PropertyRepository $properties, OccupancyQuery $occupancy, RunRepository $runs, PlanItemRepository $planItems, AlertRepository $alerts);
public function build(string $propertyId): array;
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
public function __construct(RunInputsReader $inputs, Clock $clock, string $pmsName);
public function rebuild(RunContext $context): RebuiltPlan;
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
public function __construct(RunInputs $inputs, PlanAssembler $assembler, AssembledPlan $assembled, RuleSet $rules, Clock $clock);
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
public function name(): string;
public function run(RunContext $context): StageResult;
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
public function __construct(PropertyRepository $properties, OperationStateRepository $states, Clock $clock, Logger $logger);
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
public function __construct(RunInputsReader $inputs, Clock $clock, string $pmsName);
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
public function __construct(InventorySnapshotRepository $snapshots, MarketAnchorRepository $anchors, StepLedgerRepository $ledger, OrderRepository $orders, ?ThirdPartyWatch $thirdParty = null, ?WeeklyReference $weekly = null);
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
public function issue(ValidatedPlan $plan, WriteAuthorization $auth, WriteContext $context, array $pms = [], array $recalculate = []): IssuedToken;
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
const ROLE = 'ritmo_revenue_manager';
const CAPABILITIES = ['ritmo_view', 'ritmo_manage_rules', 'ritmo_approve', 'ritmo_manage_connections'];
const GRANTS = [['ritmo_view', 'ritmo_manage_rules', 'ritmo_approve', 'ritmo_manage_connections']];
const VERSION_KEY = 'roles_version';
public static function install(KeyValueStore $settings): void;
public static function pending(int $done, bool $roleExists): array;
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
public function dashboard(): PropertyDashboard;
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

#### `Ritmo\Admin\AdminActions` (final class)

```php
public static function register(): void;
public static function names(): array;
```

#### `Ritmo\Admin\AdminPages` (final class)

```php
const DASHBOARD = 'ritmo-revenue';
const CONNECTIONS = 'ritmo-revenue-connections';
const SETUP = 'ritmo-revenue-setup';
const DIAGNOSTICS = 'ritmo-revenue-diagnostics';
const OPERATIONS = 'ritmo-revenue-operations';
public static function register(): void;
public static function vaultNotice(): void;
public static function enqueue(string $hook): void;
public static function dashboard(): void;
public static function connections(): void;
public static function setup(): void;
public static function diagnostics(): void;
public static function operations(): void;
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
```

#### `Ritmo\Admin\View` (final class)

```php
public static function render(string $template, array $view): void;
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

<!-- END GENERATED SIGNATURES -->
