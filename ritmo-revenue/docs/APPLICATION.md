# Ritmo Revenue: application and WordPress layer

The use cases (`src/Application`), the wiring for WordPress (`src/WordPress`, `src/Plugin.php`), the admin screens (`src/Admin`, `templates/`, `assets/`) and the REST API (`src/Rest`). The engine they call is in [ENGINE.md](ENGINE.md); the repositories, lock and HTTP clients in [INFRASTRUCTURE.md](INFRASTRUCTURE.md); the connectors in [CONNECTORS.md](CONNECTORS.md). Part 2 is generated from the code.

## Part 1. Guide

### 1.1 The shadow run

`ShadowRunService::run($propertyId, $budgetSeconds = 20): RunOutcome` starts or resumes the shadow run of one property. It writes nothing outside the plugin's tables.

1. Unknown property: `OutOfBoundsException`. Emergency switch engaged: `blocked`, no run is started, and a run of the property left open is closed as `stopped` under the run lock (so it never resumes from what it collected before the emergency; when another request holds the lock, that request stops it before its next stage). The scheduled `resumeOpenRuns()` goes through the same path.
2. The property's run lock (`DbLock`, key `shadow_run:<id>`, 600 s, heartbeat before every stage). Held by another request: `busy`.
3. "Today" is `Clock::today($property->timezone)`; the run covers 60 nights from today (`HORIZON_NIGHTS`). An open run of the same day resumes from its next stage; an open run of an earlier day is closed as `failed` ("The day of the property changed ...") and a new run starts.
4. Rules: the version in force when the run started (`runs.ruleset_version`; the neutral defaults when no version was saved). Rooms: the property's room types with a PMS mapping; none is a failed run.
5. `RunPipeline::continue()` runs the stages in order. Before each stage it checks the emergency switch (`stopped` when engaged) and the lock. Each stage computes, then its writes and the stage marker (`RunRepository::advance()`: stage name, summary, state for the next stage) are stored in one transaction. After the budget no new stage starts and the outcome is `running`: call again to resume. A stage that throws ends the run as `failed` with `Stage "<name>": <message>` (the plugin's own exceptions carry sentences safe to show; others are named by their class only).

| Stage | Reads | Stores | Summary |
|---|---|---|---|
| `collect` | `listListings()`, `readOverrides()`, `readPrices()` (only with the calendar read, ARCHITECTURE.md section 6), `readInventory(..., withPrices: true)`, `readBookingsModifiedSince()` (from the newest stored modification, or 365 days back) | snapshot of the PMS calendar (`InventorySnapshotRepository::store`), bookings (`upsertMany`) | listings found or missing, overrides, pricing nights, inventory nights, bookings read |
| `measure` | the snapshot of this run, the stored bookings | alerts `overbooked` (critical), `sold_mismatch`, `push_disabled`, `push_stale` (more than 30 h since the last push or never), deduplicated per room and night | occupancy per window and room against the goals, overbooked nights, mismatches, push state per listing |
| `decide` | the snapshot, the date rules and the pricing calendar of this run, the market anchors, the step ledger (`StepLedgerRepository::ledger`), the owner orders (`OrderRepository::book`) | nothing | room nights by `NightLine` code (`no_market_data` counts those without an anchor), items, deletions |
| `validate` | the validation the assembly made | nothing | items `would_write`, `no_change`, `rejected`, issue codes, issues of the plan as a whole, `refused` |
| `shadow` | the items, deletions, payload and slices of `decide` with the outcome of `validate` | `plan_items`: one row per floor, ceiling or minimum stay of an item and one per date rule deleted first (field `delete`), `status` = `would_write` / `no_change` / `rejected`, `details` = rule trace, reason parameters, relation codes, order cited, percentages, masks, orphan claim, issues; `run_payloads`: the payload and its slices in writing order | rows stored, `would_write`, slices, `writes_sent` = 0 |

How `decide` works (milestone 2): `RunInputsReader` turns what the run collected into the `RunInputs` of the plan assembly (ENGINE.md, section 1.8) and `PlanAssembler` builds, validates and slices the whole plan; `AssemblyState` keeps it as arrays for the next stages. A room night: the current price is the pricing system's (calendar read) or else the PMS price; the assembly brings it within the floor and ceiling of the date rule in force (`RuleInForce`, the reference's `pace.preco_no_vigente`), and when it moved the item's trace starts with `price_within_rule_in_force` and the price read; the floor and ceiling in force come from the calendar read or else from the date rule (fixed amount, or percentage over the room's base); the units sold are `units - available` of the PMS calendar, between 0 and the units (a blocked unit counts as sold). A night without an anchor is a `no_market_anchor` line (counted as `no_market_data`) and the engine is not asked. Without a market ruler no sales ladder strategy acts, and no night is held as closed in the PMS (neither has a source yet); the run is a full run (no light-run filter).

How `validate` treats errors: the validator ran inside the assembly with the run's context (orders, stay rules, relations, the fresh calendar); the entries it refused are `rejected` and the rest was validated again (a deletion and the item that writes its night again go together). Items whose percentages are already in force are `no_change`. A plan made for another day (`stale_plan`) fails the run (`PlanRefused`); a plan whose remaining errors concern it as a whole keeps every item `rejected` and its issues on show.

`ShadowRunService::resumeOpenRuns()` resumes every open run; the WP-Cron hook `ritmo_resume_shadow_runs` (hourly, `WordPress\Scheduler`) calls it.

The emergency switch (`EmergencySwitch`) is the option `ritmo_kill_switch` (Diagnostics screen) or the constant `RITMO_KILL_SWITCH` in wp-config.php, which the admin cannot release. The option is read with `KeyValueStore::getFresh()`, past WordPress's cache of the request, so a run sees between two stages a switch engaged by another request.

### 1.2 Wiring

`WordPress\Services::instance()` builds the objects of a request on first use: `WpdbDatabase`, `SystemClock`, `OptionsStore`, `DbLogger` with `LogSanitizer`, `SodiumVault::forSite()`, the migrator (through `dbDelta`), every repository, `DbLock`, the emergency switch, `ConnectorFactory`, `ShadowRunService`, `OccupancyQuery`, `PropertyDashboard`, `SetupService`.

`WordPress\ConnectorFactory` implements `Application\ConnectorSource`. It builds `Beds24Connector` (refresh or long-life token from the vault entry `beds24_token`, room map from `RoomTypeRepository::pmsMap()`, property ids from the setting `beds24_property_ids`, `CreditAwareHttpClient`) and `PriceLabsConnector` (key `pricelabs_api_key`, PMS name `pricelabs_pms`, `ThrottledHttpClient` with 60/min and 1000/h, `DbQuotaLedger`), both over `GuardedHttpClient(new WpHttpClient())`. A missing secret is `ConnectionNotConfigured`. The base URLs are production unless `RITMO_DEV` is true and `RITMO_BEDS24_BASE_URL` / `RITMO_PRICELABS_BASE_URL` are defined. `readsPricingCalendar()` is true unless the site defines `RITMO_PRICING_CALENDAR_READ` as `false` (project decision: that POST only reads); then the allow-list holds that one route, exact host and path. `pricingPmsName()` is the PMS name the payload carries. `test($provider)` never throws.

Lifecycle: `Activator::activate()` (migrations, vault, role "Revenue Manager" and capabilities, neutral rules when none, scheduled hook), `Deactivator::deactivate()` (unschedules), `uninstall.php` → `Uninstaller` (drops tables, `ritmo_*` options, role and capabilities only when the setting `delete_data_on_uninstall` is true). `Plugin::boot()` registers the text domain (on `init`), the upgrade check (`admin_init`: migrations when the database version is behind), the menu, the assets, the admin actions, the REST routes and the scheduled hook.

### 1.3 Dashboard and setup

- `OccupancyQuery::forProperty($id)`: occupancy of each goal window, per room and for the property, from the latest stored PMS calendar (no external call), with the traffic light of the 7-night window (`OccupancySignal`: green at or above the goal, amber up to 10 points under, red below, unknown without data).
- `PropertyDashboard::build($id)`: the property, the occupancy, the open alerts, the last shadow run, the last completed one, the push state it measured, "what would be written" (the room nights of its `would_write` items: price now, floor, ceiling and stay in force and proposed, percentages, the reason code, the owner order and relations behind it, the rule trace and the validator's warnings; nearest first, at most 60, with the total), "refused" (every room night the validator refused, with its errors) and the issues of the plan as a whole.
- `SetupService`: `createProperty()` (code, name, timezone from the PHP list, three-letter currency, tier; always in shadow mode), `saveRoomType()` (mapping to the PMS room id and the pricing listing id, amounts as typed in the form), `importPreset()` (PresetLoader validation, rules saved as a new version, properties forced to shadow mode, one transaction; `InvalidConfig` lists every error), `importSamples()` (`SampleCsv`: one `date,price` per line; each paste is a full picture of its nights and rebuilds their anchors by `AnchorBuilder::build()` and `merge()`).

### 1.4 Screens and capabilities

| Screen (menu "Ritmo Revenue") | Capability | Content |
|---|---|---|
| Dashboard | `ritmo_view` | traffic light, rooms x windows, alerts, push state, last shadow run, "what would be written" with the reason in words (`Admin\ReasonText` turns the rule trace into sentences), "Run shadow now" (`ritmo_manage_rules`) |
| Connections | `ritmo_manage_connections` | Beds24 token type, token, property ids; PriceLabs key and PMS name. Secret fields are write-only (only "configured, ends with ..." is shown); "Test connection" per system |
| Setup | `ritmo_manage_rules` | properties and their mapping, create property, map room type, import preset (file or text), competitor prices (CSV) |
| Diagnostics | `ritmo_view` | versions (plugin, database, WordPress, PHP, sodium, encryption of saved keys: `SodiumVault::protection()`, calendar read), WP-Cron and run locks, last runs, emergency switch and uninstall choice (`ritmo_manage_connections`), recent log |

The role `ritmo_revenue_manager` ("Revenue Manager") has `read` and the four capabilities; administrators get the four. `ritmo_approve` is reserved for the approval mode of a later milestone.

Every form posts to admin-post.php (`Admin\AdminActions`): the capability and the nonce (`check_admin_referer`) are checked before any field is read, values are sanitised and validated by the use case, and the action redirects back with a notice. Every value a template prints goes through `esc_html`, `esc_attr` or `esc_url` as a whole; a source test fails otherwise. The JavaScript (`assets/admin.js`) calls the REST route of the shadow run, repeats it while the outcome is `running` and reloads the page; without JavaScript the same button posts the form.

### 1.5 REST API `ritmo/v1`

Every route needs a logged-in session, the `wp_rest` nonce in `X-WP-Nonce` (401 otherwise) and a capability (403 otherwise).

| Route | Capability | Answer |
|---|---|---|
| `GET /status` | `ritmo_view` | version, database version and whether it is current, emergency switch, calendar read, properties with their last run |
| `GET /properties/{id}/occupancy` | `ritmo_view` | `OccupancyQuery::forProperty()`; 404 for an unknown property |
| `POST /properties/{id}/shadow-run` | `ritmo_manage_rules` | `RunOutcome` (`status`, `run_id`, `stage`, `error`); 409 when busy, 423 when the emergency switch blocks it, 404 for an unknown property |
| `GET /runs/{id}` | `ritmo_view` | the run, its summary, room nights by item status and how many would be written; 404 for an unknown run |

### 1.6 The throwaway site and the end-to-end test

```
bin/e2e.sh [screenshot folder]
```

1. `bin/wp-test-site.sh start` downloads WordPress (wordpress.org/latest.zip) and the SQLite integration plugin (downloads.wordpress.org) into `.wp-test/downloads`, unpacks a fresh site in `.wp-test/wordpress`, installs the SQLite drop-in, writes a test-only wp-config.php (SQLite, `WP_HTTP_BLOCK_EXTERNAL`, `RITMO_DEV`, the simulator's base URLs, `RITMO_PRICING_CALENDAR_READ` from the environment), links this folder as the plugin, installs WordPress with a test-only administrator and activates the plugin (`tests/e2e/install.php`), then serves the site on 127.0.0.1:8090 and the simulator on 127.0.0.1:8091 (scenario moved to start today).
2. `tests/e2e/seed.php` imports `presets/default.json`, creates the invented property of the simulator with its three room types mapped to the simulator's ids, and pastes six competitor prices for each of the 60 nights.
3. `tests/e2e/shadow-run.js` (Playwright, Chromium, screenshots at device scale factor 2), first pass: log in; save the connections and check that no secret comes back to the browser; test both connections; check Setup; run the shadow run from the dashboard and wait for it; check "what would be written", its reasons and "0 writes sent"; check the REST routes (401 without nonce, 404 for an unknown property); check Diagnostics; read `GET /__log` of the simulator: only GET reached it.
4. Second pass, after restarting the site with `RITMO_PRICING_CALENDAR_READ=1`: a second shadow run; only GET and `POST /pricelabs/v1/listing_prices` reached the simulator.
5. WordPress' debug log must hold no PHP error, warning, notice or deprecation from a plugin file. Both servers are stopped at the end.

Screenshots: `01-connections`, `02-setup`, `03-dashboard-before`, `04-dashboard-after-shadow-run`, `05-diagnostics`, `06-dashboard-with-calendar-read` (`.png`).

### 1.7 Where each class is

| Class | Role |
|---|---|
| `ShadowRunService`, `RunPipeline`, `RunOutcome` | Start or resume a run under the lock; run the stages; where a call left the run. |
| `Stage`, `StageResult`, `RunContext` | A stage, what it produced (summary, state, writes) and what it works with (property, mapped rooms, rules, today, nights, state). |
| `CollectStage`, `MeasureStage`, `DecideStage`, `ValidateStage`, `ShadowStage` | The five stages of 1.1. |
| `RunInputsReader`, `AssemblyState` | What the plan assembly reads, from what the run collected; the assembled plan as the arrays the next stages carry. |
| `StateCodec`, `PlanItemStatus`, `PlanRefused` | State carried between stages as arrays; statuses of stored items; refusal of a whole plan. |
| `ConnectorSource`, `ConnectionNotConfigured`, `EmergencySwitch` | Where use cases get connectors; missing credentials; the general switch. |
| `OccupancyQuery`, `OccupancySignal`, `PropertyDashboard` | Dashboard data (1.3). |
| `SetupService`, `SampleCsv` | Setup use cases (1.3). |
| `Services`, `ConnectorFactory`, `Activator`, `Deactivator`, `Uninstaller`, `Roles`, `Scheduler` | WordPress wiring and lifecycle (1.2). |
| `AdminPages`, `AdminActions`, `View`, `Notice`, `ReasonText` | Screens, form actions, templates, one-time notices, the rule trace in words (1.4). |
| `RestRoutes` | The REST API (1.5). |

### 1.8 Not in this milestone

Writing (write gate, approval and autonomous modes; the payload and slices are stored, never sent), scheduled and light runs, the nights grid with every room night, rule and order editors, the market ruler of a ladder strategy and the nights closed in the PMS, the `pt_BR` translation, licence and assistant. The step ledger is read (cadence) but nothing records steps while the plugin only shadows.

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
public readonly int $runId;
public readonly Property $property;
public readonly array $rooms;
public readonly RuleSet $rules;
public readonly DateTimeImmutable $today;
public readonly DateRange $horizon;
public readonly string $stage;
public readonly array $summary;
public readonly array $state;
public readonly string $kind;
public readonly PropertyMode $mode;
public readonly ?int $rulesetVersion;
public function __construct(int $runId, Property $property, array $rooms, RuleSet $rules, DateTimeImmutable $today, DateRange $horizon, string $stage = '', array $summary = [], array $state = [], string $kind = 'shadow', PropertyMode $mode = PropertyMode::Shadow, ?int $rulesetVersion = null);
public function after(string $stage, array $summary, array $state): self;
public function capturedAt(): ?DateTimeImmutable;
public function listings(): array;
public function pricingNights(): array;
public function overrides(): array;
public function readsPricingCalendar(): bool;
```

#### `Ritmo\Application\ShadowRun\RunInputsReader` (final class)

```php
public function __construct(InventorySnapshotRepository $snapshots, MarketAnchorRepository $anchors, StepLedgerRepository $ledger, OrderRepository $orders);
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
const HORIZON_NIGHTS = 60;
const LOCK_TTL_SECONDS = 600;
const DEFAULT_BUDGET_SECONDS = 20;
public function __construct(PropertyRepository $properties, RoomTypeRepository $roomTypes, RuleSetRepository $ruleSets, RunRepository $runs, DbLock $locks, EmergencySwitch $switch, RunPipeline $pipeline, Clock $clock, Logger $logger);
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
public function __construct(UndoDraft $draft, array $refused, array $grants);
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
public function revoke(WriteToken $token): void;
```

#### `Ritmo\Application\Write\Gate\WriteRefused` (final class extends DomainException)

```php
const UNSEALED_PLAN = 'unsealed_plan';
const WRONG_PROPERTY = 'wrong_property';
const STALE_PLAN = 'stale_plan';
const STALE_VALIDATION = 'stale_validation';
const STALE_READS = 'stale_reads';
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
public function plan(int $runId, string $propertyCode, IssuedToken $issued, StoredState $before): array;
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
public function __construct(array $rows, array $nights, array $broken);
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
public function __construct(SodiumVault $vault, KeyValueStore $settings, RoomTypeRepository $roomTypes, QuotaLogRepository $quotaLog, HttpClient $wire, Clock $clock, Logger $logger, LogSanitizer $sanitizer);
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
const RESUME_HOOK = 'ritmo_resume_shadow_runs';
public static function schedule(): void;
public static function unschedule(): void;
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
public function runPayloads(): RunPayloadRepository;
public function planItems(): PlanItemRepository;
public function alerts(): AlertRepository;
public function snapshots(): InventorySnapshotRepository;
public function locks(): DbLock;
public function emergencySwitch(): EmergencySwitch;
public function connectors(): ConnectorFactory;
public function shadowRuns(): ShadowRunService;
public function occupancy(): OccupancyQuery;
public function dashboard(): PropertyDashboard;
public function setup(): SetupService;
```

#### `Ritmo\WordPress\Uninstaller` (final class)

```php
const DELETE_DATA = 'delete_data_on_uninstall';
public function __construct(KeyValueStore $settings, Closure $removeData);
public static function forSite(): self;
public function run(): bool;
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
public static function register(): void;
public static function vaultNotice(): void;
public static function enqueue(string $hook): void;
public static function dashboard(): void;
public static function connections(): void;
public static function setup(): void;
public static function diagnostics(): void;
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

#### `Ritmo\Admin\View` (final class)

```php
public static function render(string $template, array $view): void;
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
