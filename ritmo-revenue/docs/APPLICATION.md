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
| `decide` | the snapshot, the market anchors, the step ledger | nothing | nights by reason (`no_inventory`, `no_current_price`, `no_market_data` and the engine's codes), steps |
| `validate` | the items of `decide` | nothing | items `would_write`, `no_change`, `rejected`, issue codes |
| `shadow` | the outcome of `validate` | `plan_items`: one row per floor or ceiling, `status` = `would_write` / `no_change` / `rejected`, `details` = rule trace, percentages, issues, `would_write` flag | rows stored, `writes_sent` = 0 |

How `decide` reads a room night: the current price is the pricing system's (calendar read) or else the PMS price, brought within the floor and ceiling of the date override in force (`RuleInForce`, the reference's `pace.preco_no_vigente`: under the floor by more than 0.5 it is the floor, over the ceiling it is the ceiling); when it moved, the item's trace starts with `price_within_rule_in_force` and the price read; the floor and ceiling in force come from the calendar read or else from the date override (fixed amount, or percentage over the room's base); the units sold are `units - available` of the PMS calendar, between 0 and the units (a blocked unit counts as sold). A night without an anchor (`AnchorLookup::forNight()` gives null) is counted as `no_market_data` and the engine is not asked. Small rooms are read with their group (`PaceBasis`). Steps become `PlanItem`s with `NightState::from($price, $override)` as what is in force.

How `validate` treats errors: the items with errors are set apart as `rejected` and the rest is validated again until it is valid; an error of the whole plan (`stale_plan`) fails the run (`PlanRefused`). `PercentRounding::apply()` gives the percentages; items whose percentages are already in force are `no_change`.

`ShadowRunService::resumeOpenRuns()` resumes every open run; the WP-Cron hook `ritmo_resume_shadow_runs` (hourly, `WordPress\Scheduler`) calls it.

The emergency switch (`EmergencySwitch`) is the option `ritmo_kill_switch` (Diagnostics screen) or the constant `RITMO_KILL_SWITCH` in wp-config.php, which the admin cannot release. The option is read with `KeyValueStore::getFresh()`, past WordPress's cache of the request, so a run sees between two stages a switch engaged by another request.

### 1.2 Wiring

`WordPress\Services::instance()` builds the objects of a request on first use: `WpdbDatabase`, `SystemClock`, `OptionsStore`, `DbLogger` with `LogSanitizer`, `SodiumVault::forSite()`, the migrator (through `dbDelta`), every repository, `DbLock`, the emergency switch, `ConnectorFactory`, `ShadowRunService`, `OccupancyQuery`, `PropertyDashboard`, `SetupService`.

`WordPress\ConnectorFactory` implements `Application\ConnectorSource`. It builds `Beds24Connector` (refresh or long-life token from the vault entry `beds24_token`, room map from `RoomTypeRepository::pmsMap()`, property ids from the setting `beds24_property_ids`, `CreditAwareHttpClient`) and `PriceLabsConnector` (key `pricelabs_api_key`, PMS name `pricelabs_pms`, `ThrottledHttpClient` with 60/min and 1000/h, `DbQuotaLedger`), both over `GuardedHttpClient(new WpHttpClient())`. A missing secret is `ConnectionNotConfigured`. The base URLs are production unless `RITMO_DEV` is true and `RITMO_BEDS24_BASE_URL` / `RITMO_PRICELABS_BASE_URL` are defined. `readsPricingCalendar()` is true only when `RITMO_PRICING_CALENDAR_READ === true`; then the allow-list holds that one route. `test($provider)` never throws.

Lifecycle: `Activator::activate()` (migrations, vault, role "Revenue Manager" and capabilities, neutral rules when none, scheduled hook), `Deactivator::deactivate()` (unschedules), `uninstall.php` → `Uninstaller` (drops tables, `ritmo_*` options, role and capabilities only when the setting `delete_data_on_uninstall` is true). `Plugin::boot()` registers the text domain (on `init`), the upgrade check (`admin_init`: migrations when the database version is behind), the menu, the assets, the admin actions, the REST routes and the scheduled hook.

### 1.3 Dashboard and setup

- `OccupancyQuery::forProperty($id)`: occupancy of each goal window, per room and for the property, from the latest stored PMS calendar (no external call), with the traffic light of the 7-night window (`OccupancySignal`: green at or above the goal, amber up to 10 points under, red below, unknown without data).
- `PropertyDashboard::build($id)`: the property, the occupancy, the open alerts, the last shadow run, the last completed one, the push state it measured and "what would be written": the room nights of its `would_write` items (floor and ceiling, in force and proposed, percentages, rule trace), nearest first, at most 60, with the total.
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
| `StateCodec`, `PlanItemStatus`, `PlanRefused` | State carried between stages as arrays; statuses of stored items; refusal of a whole plan. |
| `ConnectorSource`, `ConnectionNotConfigured`, `EmergencySwitch` | Where use cases get connectors; missing credentials; the general switch. |
| `OccupancyQuery`, `OccupancySignal`, `PropertyDashboard` | Dashboard data (1.3). |
| `SetupService`, `SampleCsv` | Setup use cases (1.3). |
| `Services`, `ConnectorFactory`, `Activator`, `Deactivator`, `Uninstaller`, `Roles`, `Scheduler` | WordPress wiring and lifecycle (1.2). |
| `AdminPages`, `AdminActions`, `View`, `Notice`, `ReasonText` | Screens, form actions, templates, one-time notices, the rule trace in words (1.4). |
| `RestRoutes` | The REST API (1.5). |

### 1.8 Not in this milestone

Writing (write gate, approval and autonomous modes), scheduled runs, the nights grid, rule editors, relations, stay rules, the sales ladder, owner orders, protection of sold-out nights, the `pt_BR` translation, licence and assistant. The step ledger is read (cadence) but nothing records steps while the plugin only shadows.

## Part 2. Signature reference

Generated from the code. Do not edit between the markers; run `php bin/sync-generated.php`.

<!-- BEGIN GENERATED SIGNATURES (php bin/sync-generated.php) -->

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
const NO_INVENTORY = 'no_inventory';
const NO_CURRENT_PRICE = 'no_current_price';
public function __construct(InventorySnapshotRepository $snapshots, MarketAnchorRepository $anchors, StepLedgerRepository $ledger, Clock $clock);
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
public function __construct(int $runId, Property $property, array $rooms, RuleSet $rules, DateTimeImmutable $today, DateRange $horizon, string $stage = '', array $summary = [], array $state = []);
public function after(string $stage, array $summary, array $state): self;
public function capturedAt(): ?DateTimeImmutable;
public function listings(): array;
public function pricingNights(): array;
public function overrides(): array;
public function readsPricingCalendar(): bool;
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
public function __construct(PlanItemRepository $planItems);
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
public static function planItem(PlanItem $item): array;
public static function toPlanItem(array $data): PlanItem;
public static function instant(?string $value): ?DateTimeImmutable;
```

#### `Ritmo\Application\ShadowRun\ValidateStage` (final class implements Stage)

```php
const NAME = 'validate';
public function __construct(Clock $clock);
public function name(): string;
public function run(RunContext $context): StageResult;
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
