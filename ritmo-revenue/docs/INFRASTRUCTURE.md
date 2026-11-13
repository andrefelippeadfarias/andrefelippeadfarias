# Ritmo Revenue: infrastructure

What `src/Infrastructure` offers to the application, the panel and the composition root. The conventions are in [ARCHITECTURE.md](ARCHITECTURE.md), the contracts it implements in [CONTRACTS.md](CONTRACTS.md). Part 2 is the exact signature of every public member, generated from the code; a test fails when it is stale.

## Part 1. Guide

### 1.1 Wiring

```php
$clock = new SystemClock();
$store = new OptionsStore();                    // key "db_version" is the option "ritmo_db_version"
                                                // getFresh($key) reads past WordPress's cache of the request
$db    = new WpdbDatabase($wpdb);               // tables are "{$wpdb->prefix}ritmo_<name>"

// Activation and every upgrade (idempotent):
require_once ABSPATH . 'wp-admin/includes/upgrade.php';
Migrator::standard($db, $store, static fn (string $sql) => dbDelta($sql), $db->charsetCollate())->migrate();
$vault = SodiumVault::forSite($store, $clock);  // uses the constant RITMO_SECRET_KEY when defined
$vault->install();

$sanitizer = new LogSanitizer();
$logger    = new DbLogger($db, $clock, $sanitizer);        // minimum level "info"

// HTTP: the guard goes closest to the wire, so nothing can be sent around it.
$guarded = new GuardedHttpClient(new WpHttpClient(), ReadRouteAllowList::none(), $logger);
$limiter = new RateLimiter($clock, new SystemSleeper(), [RateLimit::perMinute(60), RateLimit::perHour(1000)]);
$pricingHttp = new ThrottledHttpClient($guarded, $limiter, 'pricing');
$pmsHttp     = new CreditAwareHttpClient($guarded, $clock, new SystemSleeper(), CreditHeaders::fiveMinuteWindow());

$quota = new DbQuotaLedger(new QuotaLogRepository($db));
$locks = new DbLock($db, $clock);
```

Tests and command-line tools use `PdoDatabase::inMemory()`, `new ArrayStore()`, `new MemoryLogger()` and `new CurlHttpClient()`; `Migrator::standard($db, $store)` without the third argument runs the statements directly. The uninstall handler calls `Migrator::standard($db, $store)->uninstall()`, which drops every plugin table.

### 1.2 HTTP guard: reads, and writes only with a live token (milestone M3)

`GuardedHttpClient::request()` lets only `GET` leave the site. Any other method, to any host, ends in `WriteBlocked` before the wrapped client is called, is logged as a warning (method, host and path only) and is listed by `blockedAttempts()`. Requests with a method-override header are blocked too; a malformed method, URL or header is an `InvalidArgumentException`. The clients never follow redirects.

Two exceptions:

- `ReadRouteAllowList`: a `POST` whose host and **exact** path were added with `->with($connector, 'POST', $host, $path)` passes `request()` (the pricing system's calendar read, ARCHITECTURE.md section 6). Only `POST` can be listed.
- `GuardedHttpClient::write($token, ...)` (the `WriteHttpClient` port): only a route of the `WriteRouteAllowList` (exact paths at the base URLs the composition root declares; a booking route cannot be declared) and only the request a connector has just used a step of a live token for (`WriteAuthority::claim($token, $method, $url, $body)`: a live token alone lets nothing through; the same request may go once more, for the Beds24 token renewal after a 401). `ThrottledHttpClient` and `CreditAwareHttpClient` pass a write through and never repeat it. Without a write list and an authority every write is `WriteBlocked`.

### 1.3 Database

- `Database` has two implementations: `WpdbDatabase` (every value through `$wpdb->prepare`) and `PdoDatabase` (SQLite or MySQL through PDO).
- Statements use `?` placeholders only, and never carry a value or a `%` in their text. Table names come from `$db->table('bookings')`; identifiers are checked.
- **Every value read is a string or null**, on both implementations. Repositories cast; `DbValue` holds the conversions (instants as `YYYY-MM-DD HH:MM:SS` in UTC, nights as `YYYY-MM-DD`, structures as JSON, floats exact).
- `transaction()` nests: only the outermost call commits, and any failure rolls everything back.
- A `Migration` is declarative (`tables()`): applying it creates missing tables, columns and indexes and never drops anything. To change a table, add a migration that states the whole table again; a new column must be nullable or have a default.

Tables (all prefixed, all with an auto-incremented `id`):

| Migration | Tables |
|---|---|
| 1 Configuration | `properties`, `room_types`, `relations`, `periods`, `rulesets`, `price_factors` |
| 2 Market | `competitors`, `price_samples`, `market_anchor` |
| 3 Operation | `orders`, `bookings`, `snapshots`, `runs`, `plan_items`, `write_ops`, `step_ledger`, `change_log`, `alerts`, `pending_items`, `api_quota_log` |
| 4 Housekeeping | `locks`, `log` |
| 5 Run stages | `runs` again, with `run_day`, `stage` and `state`: a run made of resumable stages (`RunRepository::advance()`, `state()`, `openRun()`) |
| 6 Run payloads | `run_payloads`: the payload of a run's validated plan and its slices, in writing order (M2) |
| 7 Write path | `write_ops` again (the write-ahead journal: slice, label, dates, state before, intended and read back, token, plan and body hashes, the run that classified the row) and `api_quota_log` again (outcome, response id and error of a recalculation attempt) |
| 8 Confirmation | `change_log` again (group, undone change, confirmation level, order, amounts) and `step_ledger` again (reached the channel at, undone at, journal reference); `plan_approvals` (plans waiting for a person), `night_observations` (what each read found per room night), `detected_freezes` (nights frozen because someone else changed them), `wave_holds` (the hold of a cancellation wave) |
| 9 Operations | `scheduled_runs` (slot occurrences of each property), `operation_state` (pause and cursors), `booking_movements` (processed versions of bookings), `alerts` again (lifecycle: evidence, acknowledgement, resolution, e-mail, digest, occurrences) |
| 10 Screens | `pending_items` again: the to-do list of the screens (kind, room night, done by and when; `Query\Screens\TodoRepository`) |
| 11 Market series | `competitors` again (tags, profile, archived), `price_samples` again (one row per competitor stay: check-in, check-out, basis, currency, captured at, source, import), `market_imports` (each CSV import by its file hash: who, when, rows kept and refused; the file itself is never kept), `market_series` (the series of each night as `SeriesBuilder` built them, with the inputs' hash), `ladder_reference` (the reference values a ladder strategy version started from) |
| 12 Reports | `report_snapshots` (each 12-month review saved), `revenue_targets` (targets and adjustments per month), `holiday_targets` (targets of a holiday) |

`bookings` stores what `BookingRecord` carries and nothing else; a test lists its columns and fails when one that could identify a guest is added. No table can hold guest data: bookings appear only by their PMS id. `relations`, `periods` and `price_factors` of migration 1 are reserved and unused: relations and periods live in the rule set, which is versioned and validated (spec 05, D3). `change_log` holds the night changes (`ChangeLogRepository`) and the operation events without a provider (`OperationEventRepository`: modes, approvals, the emergency switch, releases, undo requests, listing values that changed).

### 1.4 Repositories

All in `Ritmo\Infrastructure\Db\Repository`, built with the `Database` (and the `Clock` when they stamp rows). They take and return the domain DTOs where one exists; the others return arrays with snake_case keys and typed values (dates as `DateTimeImmutable`, enums as enums), documented on each method.

| Repository | Notes |
|---|---|
| `PropertyRepository`, `RoomTypeRepository` | `save()` creates or replaces by id/code. Lookups are exact, and a code that differs from an existing one only in case is refused (MySQL and SQLite disagree on case). `RoomTypeRepository::pmsMap()` is the room map a PMS connector is built with. |
| `RuleSetRepository` | `save()` writes a new version and returns its number; saving rules equal to the current version returns the current number. No method edits or deletes a version. `find()` reads a version stored by an earlier version of the plugin with the settings added since at their neutral defaults (`RuleSet::fromStored()`); `upgradeCurrent()` saves the completed rules once as a new version (the activator calls it after an update). |
| `BookingRepository` | `upsert($pms, $booking, $syncedAt)` is keyed by connector id + PMS reference. `overlapping($from, $to)` returns every status, like `PmsConnector::readBookingsByStay()`. `latestModification($pms)` is the cursor for incremental reads. |
| `InventorySnapshotRepository` | Keeps every capture; `latest($room, $from, $to, $asOf)` answers with the calendar as known at an instant. |
| `MarketAnchorRepository` | One anchor per property and night; `range()` has the shape of `MarketDataProvider::readAnchors()`. |
| `PriceSampleRepository` | Samples are only added. |
| `RunRepository`, `PlanItemRepository` | `start()` / `advance()` after each completed stage (stage name, summary, state for the next stage) / `finish()` as `completed`, `failed` or `stopped` (the state is dropped); `openRun()` finds a run to resume. `addAll($runId, $items)` stores a whole plan or nothing. |
| `AlertRepository` | `raise()` with a `$dedupeKey` refreshes the open alert of the same key instead of repeating it. |
| `StepLedgerRepository` | Every pace step of a night; `lastStep()` for the cadence rule, `ledger($propertyId, $from, $to)` as the `StepLedger` the plan assembly reads (a step marked on the channel counts as arrived when recorded), `markReachedChannel()` after a push. |
| `OrderRepository` | Owner orders: `add()` keeps the order's fields as `OrderReader` takes them (a row that does not read is refused), `book($propertyId, $from)` gives the `OrderBook` of the active orders still in force; an order that no longer reads fails the run that needs it. |
| `RunPayloadRepository` | `store($runId, $propertyId, $payload, $slices)` keeps the payload and the slices of a run, all or nothing; `forRun()` reads them back. |
| `QuotaLogRepository` | The rows behind `DbQuotaLedger`. |
| `WriteOpRepository`, `RecalcAttemptRepository` | The write journal (`insertPlanned`, `markSending` before the call, `finish`, `observe`, `unsettled`, `resolve`, `refusalStreak`, `listingWritten`: whether a listing change of the plugin set the values read) and the recalculation attempts (pending before the call). |
| `ChangeLogRepository`, `OperationEventRepository` | The change log of nights (before, after, inverse operation; `markUndone`) and the events of the operation (`record`, `latest`, `lastTo`). |
| `WrittenStepRepository` | The step ledger as the write path keeps it: steps of stored nights, delivered at the channel, undone. |
| `PlanApprovalRepository` | Requests waiting for a person: `create`, `pending`, `approved`, `latest`, `decide` (only from pending), `close`, `finish`. |
| `NightObservationRepository`, `DetectedFreezeRepository`, `WaveHoldRepository` | What each read found, the nights frozen by a change of someone else, the hold of a cancellation wave. |
| `CompetitorRepository`, `StaySampleRepository`, `MarketImportRepository`, `MarketSeriesRepository`, `LadderReferenceRepository` | The market (MARKET.md): competitors, one sample per competitor stay (deduplicated by competitor, stay and basis), the imports by file hash, the series of each night, the reference of a ladder strategy version. |
| `OrderLedgerRepository`, `EditorEventRepository` | The orders as the Orders screen lists them (copy and end, never edit) and the editors' events in `change_log` (rule-set versions, orders, competitors, market imports). |
| `Query\Screens\AlertListReader`, `Query\Screens\ChangeLogReader`, `Query\Screens\TodoRepository` | The paged reads of the Alerts and Changes screens and the to-do list. |
| `Schedule\ScheduledRunRepository`, `Schedule\OperationStateRepository`, `Schedule\BookingMovementRepository`, `Schedule\AlertLedger` | The slots, the pause and cursors, the processed booking versions, the alerts with their lifecycle (`since()`: the alerts of a code first raised since an instant, for the conditions of a mode) (OPERATIONS.md, ALERTS.md). |

### 1.5 Secrets

`SodiumVault` encrypts with `sodium_crypto_secretbox`, one subkey per entry. For the interface there is only `status($name)`: configured or not, and when it was saved; nothing of the secret is kept in clear, not even some of its characters. `reveal($name)` is for the connector that sends the secret; its result must never be shown, logged or returned by an endpoint. Register revealed secrets with `LogSanitizer::registerSecret()` so that they are masked wherever they appear.

Where the key lives (`protection()`, shown in Diagnostics), from the safest:

| Protection | Key | What a copy of the database alone gives |
|---|---|---|
| `configured_key` | `RITMO_SECRET_KEY` (32 characters or more) in wp-config.php | nothing |
| `site_salts` | the stored random seed bound to `AUTH_KEY` and `SECURE_AUTH_KEY` of wp-config.php | nothing; regenerating those salts makes the saved keys unreadable (enter them again) |
| `database_only` | the stored seed alone (the site has no salts, or the placeholders of wp-config-sample.php) | every secret: Diagnostics shows a warning |
| `unavailable` | `RITMO_SECRET_KEY` shorter than 32 characters | nothing is stored or revealed until it is fixed |

Threat model: the vault protects the keys against a leak of the database (a backup, a dump, an SQL injection in another plugin). It does not protect against someone who can read wp-config.php or run code on the site.

An invalid `RITMO_SECRET_KEY` does not throw: `problem()` explains it, `put()`, `reveal()` and `rotate()` throw `SecretUnavailable`, an admin notice reports it to whoever manages the connections, and activation and the screens keep working. `install()` (activation) creates the seed when needed and calls `upgrade()`, which moves every entry it can read to the current key (entries of older versions, or entries of the seed once `RITMO_SECRET_KEY` is set) and drops what older versions kept next to an entry.

`rotate()` re-encrypts every entry in one write: without a configured key it replaces the stored seed; with one it moves everything under it and drops the seed. After `RITMO_SECRET_KEY` changed, pass the old value: `rotate($oldKey)`.

### 1.6 Limits, quotas, locks and the log

- `RateLimiter` enforces several sliding windows per key and waits through the `Sleeper`; beyond its maximum wait it throws `RateLimited`. It counts the calls of one PHP process. Quotas that must hold across runs go to the `QuotaLedger`.
- `DbQuotaLedger` counts over rolling windows, failures included. A call recorded with a scope (a listing) also counts for the account: read with the scope `''`. `opensAt()` says when the next call fits.
- `CreditAwareHttpClient` waits for the credit window an API reports in its headers before the next request, and repeats a `GET` once after a `429`.
- `DbLock::acquire($key, $ttl)` returns a `LockHandle` or null; long work calls `heartbeat()` and stops when it returns false.
- `DbLogger` drops entries below its minimum level and passes every entry through `LogSanitizer` (credentials redacted, guest data and whole bodies omitted, texts cut). When the table cannot be written, the entry goes to PHP's error log.

### 1.7 Known limits

- No MySQL server was available where this was built. The PHPUnit suite runs on SQLite, with the MySQL statements covered by text comparison (dbDelta format, upsert, insert-ignore) and by a `$wpdb` double. On a real WordPress with the SQLite integration, `php tests/Integration/Db/wordpress-smoke.php <wordpress folder>` exercises the migrations through `dbDelta()`, every repository, the lock, the log, the vault and the ledger (its header says how to prepare the folder). A run on MySQL belongs to the owner's hosting.
- Instants are stored to the second.
- While a statement runs, `WpdbDatabase` sets PHP's `precision` to -1 and puts it back: WordPress on SQLite turns numbers into text inside PHP, and floats would otherwise come back cut to 14 digits.

## Part 2. Signature reference

Generated from the code. Do not edit between the markers; run `php bin/sync-generated.php`.

<!-- BEGIN GENERATED SIGNATURES (php bin/sync-generated.php) -->

#### `Ritmo\Infrastructure\Db\Database` (interface)

```php
const MYSQL = 'mysql';
const SQLITE = 'sqlite';
public function dialect(): string;
public function prefix(): string;
public function table(string $name): string;
public function select(string $sql, array $params = []): array;
public function selectRow(string $sql, array $params = []): ?array;
public function selectValue(string $sql, array $params = []): ?string;
public function execute(string $sql, array $params = []): int;
public function insert(string $table, array $row): int;
public function insertIgnore(string $table, array $row): bool;
public function upsert(string $table, array $row, array $uniqueColumns): void;
public function update(string $table, array $values, array $where): int;
public function delete(string $table, array $where): int;
public function transaction(callable $work): mixed;
```

#### `Ritmo\Infrastructure\Db\DatabaseException` (final class extends RuntimeException)

```php
// no public members
```

#### `Ritmo\Infrastructure\Db\DbValue` (final class)

```php
public static function instant(DateTimeInterface $instant): string;
public static function nullableInstant(?DateTimeInterface $instant): ?string;
public static function toInstant(?string $stored): ?DateTimeImmutable;
public static function requireInstant(?string $stored): DateTimeImmutable;
public static function requireNight(?string $stored): DateTimeImmutable;
public static function night(DateTimeInterface $night): string;
public static function nullableNight(?DateTimeInterface $night): ?string;
public static function toNight(?string $stored): ?DateTimeImmutable;
public static function json(array $value): string;
public static function toArray(?string $stored): array;
public static function clip(string $value, int $maxLength): string;
public static function word(string $value, int $maxLength): string;
public static function reference(string $value, int $maxLength): string;
public static function nullableFloat(?string $stored): ?float;
public static function nullableInt(?string $stored): ?int;
```

#### `Ritmo\Infrastructure\Db\Migration\Migration` (interface)

```php
public function version(): int;
public function description(): string;
public function tables(): array;
```

#### `Ritmo\Infrastructure\Db\Migration\Migration0001Configuration` (final class implements Migration)

```php
public function version(): int;
public function description(): string;
public function tables(): array;
```

#### `Ritmo\Infrastructure\Db\Migration\Migration0002Market` (final class implements Migration)

```php
public function version(): int;
public function description(): string;
public function tables(): array;
```

#### `Ritmo\Infrastructure\Db\Migration\Migration0003Operation` (final class implements Migration)

```php
public function version(): int;
public function description(): string;
public function tables(): array;
```

#### `Ritmo\Infrastructure\Db\Migration\Migration0004Housekeeping` (final class implements Migration)

```php
public function version(): int;
public function description(): string;
public function tables(): array;
```

#### `Ritmo\Infrastructure\Db\Migration\Migration0005RunStages` (final class implements Migration)

```php
public function version(): int;
public function description(): string;
public function tables(): array;
```

#### `Ritmo\Infrastructure\Db\Migration\Migration0006RunPayloads` (final class implements Migration)

```php
public function version(): int;
public function description(): string;
public function tables(): array;
```

#### `Ritmo\Infrastructure\Db\Migration\Migration0007WritePath` (final class implements Migration)

```php
public function version(): int;
public function description(): string;
public function tables(): array;
```

#### `Ritmo\Infrastructure\Db\Migration\Migration0008Confirmation` (final class implements Migration)

```php
public function version(): int;
public function description(): string;
public function tables(): array;
```

#### `Ritmo\Infrastructure\Db\Migration\Migration0009Operations` (final class implements Migration)

```php
public function version(): int;
public function description(): string;
public function tables(): array;
```

#### `Ritmo\Infrastructure\Db\Migration\Migration0010Screens` (final class implements Migration)

```php
public function version(): int;
public function description(): string;
public function tables(): array;
```

#### `Ritmo\Infrastructure\Db\Migration\Migration0011MarketSeries` (final class implements Migration)

```php
public function version(): int;
public function description(): string;
public function tables(): array;
```

#### `Ritmo\Infrastructure\Db\Migration\Migration0012Reports` (final class implements Migration)

```php
public function version(): int;
public function description(): string;
public function tables(): array;
```

#### `Ritmo\Infrastructure\Db\Migration\Migrations` (final class)

```php
public static function all(): array;
```

#### `Ritmo\Infrastructure\Db\Migration\Migrator` (final class)

```php
const VERSION_KEY = 'db_version';
public function __construct(Database $db, KeyValueStore $store, array $migrations, ?Closure $dbDelta = null, string $charsetCollate = '');
public static function standard(Database $db, KeyValueStore $store, ?Closure $dbDelta = null, string $charsetCollate = ''): self;
public function currentVersion(): int;
public function latestVersion(): int;
public function isUpToDate(): bool;
public function migrate(): array;
public function tables(): array;
public function uninstall(): void;
```

#### `Ritmo\Infrastructure\Db\NestedTransactions` (trait)

```php
public function transaction(callable $work): mixed;
```

#### `Ritmo\Infrastructure\Db\PdoDatabase` (final class implements Database)

```php
public function __construct(PDO $pdo, string $prefix = 'wp_ritmo_');
public static function inMemory(string $prefix = 'wp_ritmo_'): self;
public function dialect(): string;
public function prefix(): string;
public function table(string $name): string;
public function select(string $sql, array $params = []): array;
public function selectRow(string $sql, array $params = []): ?array;
public function selectValue(string $sql, array $params = []): ?string;
public function execute(string $sql, array $params = []): int;
public function insert(string $table, array $row): int;
public function insertIgnore(string $table, array $row): bool;
public function upsert(string $table, array $row, array $uniqueColumns): void;
public function update(string $table, array $values, array $where): int;
public function delete(string $table, array $where): int;
public function transaction(callable $work): mixed;
```

#### `Ritmo\Infrastructure\Db\Query\Screens\AlertListReader` (final class implements AlertList)

```php
public function __construct(Database $db);
public function count(AlertFilter $filter): int;
public function page(AlertFilter $filter, int $offset, int $limit): array;
public function find(int $id): ?array;
public function countBySeverity(?string $propertyId, array $severities): int;
```

#### `Ritmo\Infrastructure\Db\Query\Screens\ChangeLogReader` (final class implements ChangeList)

```php
public function __construct(Database $db, ChangeLogRepository $changes);
public function count(ChangeFilter $filter): int;
public function page(ChangeFilter $filter, int $offset, int $limit): array;
```

#### `Ritmo\Infrastructure\Db\Query\Screens\TodoRepository` (final class implements TodoStore)

```php
public function __construct(Database $db);
public function add(TodoItem $item, DateTimeImmutable $now, bool $once = false): int;
public function find(int $id): ?TodoItem;
public function open(?string $propertyId, int $limit = 200): array;
public function done(?string $propertyId, int $limit = 50): array;
public function setStatus(int $id, string $status, int $userId, DateTimeImmutable $now): bool;
public function closeKeys(array $keys, DateTimeImmutable $now): int;
public function openKeys(string $kind): array;
public function countDue(?string $propertyId, DateTimeImmutable $today): int;
```

#### `Ritmo\Infrastructure\Db\Repository\AlertRepository` (final class)

```php
const INFO = 'info';
const WARNING = 'warning';
const CRITICAL = 'critical';
const OPEN = 'open';
const ACKNOWLEDGED = 'acknowledged';
const RESOLVED = 'resolved';
public function __construct(Database $db, Clock $clock);
public function raise(string $code, string $severity, string $message, array $context = [], string $propertyId = '', string $roomCode = '', ?DateTimeImmutable $night = null, string $dedupeKey = '', ?int $runId = null): int;
public function find(int $id): ?array;
public function unresolved(?string $propertyId = null, int $limit = 100): array;
public function countOpen(?string $propertyId = null): int;
public function acknowledge(int $id): void;
public function resolve(int $id): void;
```

#### `Ritmo\Infrastructure\Db\Repository\BookingRepository` (final class)

```php
public function __construct(Database $db);
public function upsert(string $pms, BookingRecord $booking, DateTimeImmutable $syncedAt): void;
public function upsertMany(string $pms, array $bookings, DateTimeImmutable $syncedAt): int;
public function find(string $pms, string $pmsRef): ?BookingRecord;
public function overlapping(DateTimeImmutable $from, DateTimeImmutable $to, ?string $roomCode = null): array;
public function latestModification(string $pms): ?DateTimeImmutable;
public function purgeDepartedBefore(DateTimeImmutable $date): int;
```

#### `Ritmo\Infrastructure\Db\Repository\ChangeLogRepository` (final class)

```php
public function __construct(Database $db);
public function recordAll(array $records): array;
public function record(ChangeRecord $record): int;
public function byIds(array $ids): array;
public function forRun(int $runId): array;
public function latest(string $propertyId, int $limit = 200): array;
public function markUndone(array $ids, DateTimeImmutable $at): int;
```

#### `Ritmo\Infrastructure\Db\Repository\CodeLookup` (final class)

```php
public static function id(Database $db, string $table, string $code): ?int;
```

#### `Ritmo\Infrastructure\Db\Repository\CompetitorRepository` (final class)

```php
public function __construct(Database $db, Clock $clock);
public function add(string $propertyId, array $fields): int;
public function update(string $propertyId, int $id, array $fields): bool;
public function archive(string $propertyId, int $id): bool;
public function find(string $propertyId, int $id): ?array;
public function forProperty(string $propertyId, bool $activeOnly = false): array;
public function profiles(string $propertyId): array;
public function codeTaken(string $propertyId, string $code, int $exceptId = 0): bool;
public function lastChange(string $propertyId): ?DateTimeImmutable;
```

#### `Ritmo\Infrastructure\Db\Repository\DetectedFreezeRepository` (final class)

```php
public function __construct(Database $db);
public function add(string $propertyId, DetectedFreeze $freeze, ?int $runId = null): int;
public function unreleased(string $propertyId): array;
public function release(int $id, int $userId, DateTimeImmutable $at): bool;
```

#### `Ritmo\Infrastructure\Db\Repository\EditorEventRepository` (final class)

```php
const RULES_VERSION = 'rules_version';
const ORDER = 'order';
const COMPETITOR = 'competitor';
const MARKET_IMPORT = 'market_import';
public function __construct(Database $db);
public function record(string $propertyId, string $kind, string $from, string $to, string $reason, string $actor, DateTimeImmutable $at, array $details = []): int;
public function latest(string $propertyId, array $kinds, int $limit = 50): array;
```

#### `Ritmo\Infrastructure\Db\Repository\InventorySnapshotRepository` (final class)

```php
public function __construct(Database $db);
public function store(string $roomCode, array $nights, DateTimeImmutable $capturedAt, ?int $runId = null): int;
public function latest(string $roomCode, DateTimeImmutable $from, DateTimeImmutable $to, ?DateTimeImmutable $asOf = null): array;
public function lastCapturedAt(string $roomCode): ?DateTimeImmutable;
public function purgeCapturedBefore(DateTimeImmutable $instant): int;
```

#### `Ritmo\Infrastructure\Db\Repository\LadderReferenceRepository` (final class)

```php
public function __construct(Database $db);
public function take(string $propertyId, string $strategyId, array $prices, DateTimeImmutable $takenAt): int;
public function latestVersion(string $propertyId, string $strategyId): ?int;
public function latest(string $propertyId, string $strategyId, DateTimeImmutable $from, DateTimeImmutable $to): array;
```

#### `Ritmo\Infrastructure\Db\Repository\MarketAnchorRepository` (final class)

```php
public function __construct(Database $db, Clock $clock);
public function save(string $propertyId, MarketAnchor $anchor): void;
public function saveMany(string $propertyId, iterable $anchors): void;
public function find(string $propertyId, DateTimeImmutable $night): ?MarketAnchor;
public function range(string $propertyId, DateTimeImmutable $from, DateTimeImmutable $to): array;
```

#### `Ritmo\Infrastructure\Db\Repository\MarketImportRepository` (final class)

```php
const MAX_PROBLEMS = 200;
public function __construct(Database $db);
public function record(string $propertyId, int $userId, string $fileName, string $sha256, int $bytes, array $counts, array $problems, DateTimeImmutable $at): int;
public function settle(int $id, int $imported, int $replaced): void;
public function importedAt(string $propertyId, string $sha256): ?DateTimeImmutable;
public function latest(string $propertyId, int $limit = 10): array;
```

#### `Ritmo\Infrastructure\Db\Repository\MarketSeriesRepository` (final class)

```php
public function __construct(Database $db);
public function replace(string $propertyId, array $values, string $inputsHash, DateTimeImmutable $builtAt): int;
public function range(string $propertyId, DateTimeImmutable $from, DateTimeImmutable $to): array;
public function inputsHash(string $propertyId): ?string;
```

#### `Ritmo\Infrastructure\Db\Repository\NightObservationRepository` (final class)

```php
public function __construct(Database $db);
public function recordChanged(string $propertyId, array $observations, ?int $runId = null): int;
public function latest(string $propertyId, DateTimeImmutable $from, DateTimeImmutable $to): array;
public function purgeBefore(string $propertyId, DateTimeImmutable $before): int;
```

#### `Ritmo\Infrastructure\Db\Repository\OperationEventRepository` (final class)

```php
const MODE = 'mode';
const APPROVAL = 'approval';
const EMERGENCY = 'emergency';
const WAVE_RELEASE = 'wave_release';
const FREEZE_RELEASE = 'freeze_release';
const UNDO = 'undo_request';
const LISTING = 'listing_values';
const PAUSE = 'pause';
public function __construct(Database $db);
public function record(string $propertyId, string $kind, string $from, string $to, string $reason, string $actor, DateTimeImmutable $at, ?int $runId = null): int;
public function latest(string $propertyId, ?string $kind = null, int $limit = 50): array;
public function lastTo(string $propertyId, string $kind, string $to): ?array;
```

#### `Ritmo\Infrastructure\Db\Repository\OrderLedgerRepository` (final class)

```php
const ENDED = 'ended';
public function __construct(Database $db);
public function active(string $propertyId, DateTimeImmutable $from): array;
public function ended(string $propertyId, DateTimeImmutable $today, DateTimeImmutable $since): array;
public function find(string $propertyId, int $id): ?array;
public function end(string $propertyId, int $id, DateTimeImmutable $at): bool;
```

#### `Ritmo\Infrastructure\Db\Repository\OrderRepository` (final class)

```php
const ACTIVE = 'active';
public function __construct(Database $db, Clock $clock);
public function add(string $propertyId, array $order, int $userId = 0): int;
public function book(string $propertyId, DateTimeImmutable $from): OrderBook;
```

#### `Ritmo\Infrastructure\Db\Repository\PlanApprovalRepository` (final class)

```php
const PENDING = 'pending';
const APPROVED = 'approved';
public function __construct(Database $db);
public function create(?int $runId, string $propertyId, string $kind, string $planHash, DateTimeImmutable $planDay, ?int $rulesetVersion, DateTimeImmutable $calendarReadAt, DateTimeImmutable $expiresAt, array $entries, DateTimeImmutable $now): int;
public function find(int $id): ?array;
public function pending(string $propertyId, ?string $kind = null): array;
public function approved(string $propertyId, ?string $kind = null): array;
public function latest(string $propertyId, int $limit = 20): array;
public function decide(int $id, string $status, array $approvedKeys, array $rejectedKeys, int $userId, string $capability, DateTimeImmutable $at, string $note = ''): bool;
public function close(int $id, string $status, string $reason): bool;
public function finish(int $id, string $fromStatus, string $status, string $reason, ?int $writeRunId): bool;
```

#### `Ritmo\Infrastructure\Db\Repository\PlanItemRepository` (final class)

```php
public function __construct(Database $db, Clock $clock);
public function add(int $runId, string $propertyId, string $roomCode, DateTimeImmutable $night, string $field, ?float $current, ?float $proposed, string $reason = '', array $details = [], PricePlan $plan = PricePlan::Sent, string $status = 'proposed'): int;
public function addAll(int $runId, iterable $items): int;
public function forRun(int $runId): array;
public function forNight(string $roomCode, DateTimeImmutable $night, int $limit = 20): array;
```

#### `Ritmo\Infrastructure\Db\Repository\PriceSampleRepository` (final class)

```php
public function __construct(Database $db);
public function add(string $propertyId, int $competitorId, DateTimeImmutable $night, float $price, DateTimeImmutable $capturedAt, string $source = 'manual', PricePlan $plan = PricePlan::PublicRate, bool $available = true, ?int $minStay = null): int;
public function forNights(string $propertyId, DateTimeImmutable $from, DateTimeImmutable $to, ?DateTimeImmutable $capturedSince = null): array;
public function purgeCapturedBefore(DateTimeImmutable $instant): int;
```

#### `Ritmo\Infrastructure\Db\Repository\PropertyRepository` (final class)

```php
public function __construct(Database $db, Clock $clock);
public function save(Property $property): void;
public function find(string $id): ?Property;
public function all(): array;
public function setMode(string $id, PropertyMode $mode): void;
public function delete(string $id): void;
```

#### `Ritmo\Infrastructure\Db\Repository\QuotaLogRepository` (final class)

```php
public function __construct(Database $db);
public function add(string $provider, string $action, string $scope, bool $succeeded, DateTimeImmutable $at): int;
public function instants(string $provider, string $action, string $scope, DateTimeImmutable $since, bool $inclusive = true): array;
public function recent(int $limit = 50): array;
public function purgeBefore(DateTimeImmutable $instant): int;
```

#### `Ritmo\Infrastructure\Db\Repository\RecalcAttemptRepository` (final class)

```php
const ACTION = 'recalculation';
public function __construct(Database $db);
public function record(string $provider, string $listing, string $outcome, DateTimeImmutable $at, ?string $error = null, string $responseId = ''): int;
public function settle(int $id, string $outcome, ?string $error = null): void;
public function since(string $provider, DateTimeImmutable $since): array;
```

#### `Ritmo\Infrastructure\Db\Repository\RoomTypeRepository` (final class)

```php
public function __construct(Database $db, Clock $clock);
public function save(RoomType $room): void;
public function find(string $code): ?RoomType;
public function forProperty(string $propertyId): array;
public function all(): array;
public function pmsMap(): array;
public function delete(string $code): void;
```

#### `Ritmo\Infrastructure\Db\Repository\RuleSetRepository` (final class)

```php
public function __construct(Database $db, Clock $clock);
public function save(RuleSet $rules, string $note = '', int $userId = 0): int;
public function currentVersion(): ?int;
public function current(): ?RuleSet;
public function find(int $version): ?RuleSet;
public function upgradeCurrent(): ?int;
public function history(int $limit = 50): array;
```

#### `Ritmo\Infrastructure\Db\Repository\RunPayloadRepository` (final class)

```php
const PAYLOAD = 'payload';
const SLICE = 'slice';
public function __construct(Database $db, Clock $clock);
public function store(int $runId, string $propertyId, array $payload, array $slices): void;
public function forRun(int $runId): array;
```

#### `Ritmo\Infrastructure\Db\Repository\RunRepository` (final class)

```php
const RUNNING = 'running';
const COMPLETED = 'completed';
const FAILED = 'failed';
const STOPPED = 'stopped';
public function __construct(Database $db);
public function start(string $propertyId, string $kind, PropertyMode $mode, ?int $ruleSetVersion, DateTimeImmutable $startedAt, ?DateTimeImmutable $runDay = null): int;
public function advance(int $runId, string $stage, array $summary, array $state): void;
public function state(int $runId): array;
public function openRun(string $propertyId, string $kind): ?array;
public function finish(int $runId, string $status, array $summary, DateTimeImmutable $finishedAt, ?string $error = null): void;
public function find(int $runId): ?array;
public function latest(?string $propertyId = null, int $limit = 20): array;
```

#### `Ritmo\Infrastructure\Db\Repository\StaySampleRepository` (final class)

```php
public function __construct(Database $db);
public function store(string $propertyId, array $rows, ?int $importId): array;
public function samples(string $propertyId, DateTimeImmutable $fromNight, DateTimeImmutable $capturedSince): array;
public function page(string $propertyId, array $filters, int $offset, int $limit): array;
public function newestCapture(string $propertyId, DateTimeImmutable $from, DateTimeImmutable $to): ?DateTimeImmutable;
public function countsByCompetitor(string $propertyId, DateTimeImmutable $since): array;
public function lastId(string $propertyId): int;
```

#### `Ritmo\Infrastructure\Db\Repository\StepLedgerRepository` (final class)

```php
const UP = 'up';
const DOWN = 'down';
public function __construct(Database $db);
public function record(string $propertyId, string $roomCode, DateTimeImmutable $night, string $direction, float $from, float $to, DateTimeImmutable $recordedAt, string $paceStatus = '', ?int $unitsSold = null, ?int $unitsTotal = null, string $countBasis = 'room', ?int $runId = null): int;
public function lastStep(string $roomCode, DateTimeImmutable $night): ?array;
public function ledger(string $propertyId, DateTimeImmutable $from, DateTimeImmutable $to): StepLedger;
public function forNight(string $roomCode, DateTimeImmutable $night): array;
public function markReachedChannel(string $roomCode, DateTimeImmutable $pushedAt): int;
```

#### `Ritmo\Infrastructure\Db\Repository\WaveHoldRepository` (final class)

```php
public function __construct(Database $db);
public function open(string $propertyId): ?WaveHold;
public function hold(string $propertyId, WaveHold $hold, array $bookingRefs, DateTimeImmutable $now): int;
public function release(string $propertyId, int $userId, DateTimeImmutable $at): bool;
```

#### `Ritmo\Infrastructure\Db\Repository\WriteOpRepository` (final class)

```php
const UNSETTLED = ['planned', 'sending', 'accepted', 'unknown'];
public function __construct(Database $db);
public function insertPlanned(array $row, DateTimeImmutable $at): int;
public function markSending(int $id, DateTimeImmutable $at): void;
public function finish(int $id, string $status, ?int $httpStatus, ?string $error, DateTimeImmutable $at): void;
public function observe(int $id, string $status, array $observed): void;
public function resolve(array $ids, int $runId, string $status): void;
public function ofRun(int $runId): array;
public function unsettled(string $propertyCode, int $exceptRunId): array;
public function listingWritten(string $propertyCode, string $listingRef, array $values): bool;
public function refusalStreak(string $propertyCode, string $bodyHash): int;
```

#### `Ritmo\Infrastructure\Db\Repository\WrittenStepRepository` (final class)

```php
public function __construct(Database $db);
public function record(string $propertyId, StepRecord $step, ?int $runId = null, string $writeRef = ''): int;
public function pending(string $propertyId): array;
public function markDelivered(array $delivered): int;
public function markUndone(int $runId, string $roomCode, DateTimeImmutable $night, DateTimeImmutable $at): int;
public function ledger(string $propertyId, DateTimeImmutable $from, DateTimeImmutable $to): StepLedger;
```

#### `Ritmo\Infrastructure\Db\Schema\Column` (final class)

```php
public readonly string $name;
public readonly ColumnType $type;
public readonly int $length;
public readonly bool $nullable;
public readonly string|int|float|null $default;
public static function id(): self;
public static function bigInt(string $name): self;
public static function int(string $name): self;
public static function bool(string $name): self;
public static function float(string $name): self;
public static function text(string $name, int $length): self;
public static function longText(string $name): self;
public static function date(string $name): self;
public static function dateTime(string $name): self;
public function nullable(): self;
public function default(string|int|float $value): self;
```

#### `Ritmo\Infrastructure\Db\Schema\ColumnType` (enum)

```php
case Id = 'id';
case BigInt = 'bigint';
case Int = 'int';
case Bool = 'bool';
case Float = 'float';
case Text = 'text';
case LongText = 'longtext';
case Date = 'date';
case DateTime = 'datetime';
public function mysql(int $length): string;
public function sqlite(): string;
public function isNumeric(): bool;
```

#### `Ritmo\Infrastructure\Db\Schema\DdlRenderer` (final class)

```php
public function forDbDelta(Table $table, string $tableName, string $charsetCollate = ''): string;
public function create(Table $table, string $tableName, string $dialect, string $charsetCollate = ''): array;
public function addColumn(Column $column, string $tableName, string $dialect): string;
public function sqliteIndex(Index $index, string $tableName): string;
```

#### `Ritmo\Infrastructure\Db\Schema\Index` (final class)

```php
public readonly string $name;
public readonly array $columns;
public readonly bool $unique;
public static function unique(string $name, string $columns): self;
public static function plain(string $name, string $columns): self;
```

#### `Ritmo\Infrastructure\Db\Schema\SchemaInspector` (final class)

```php
public function __construct(Database $db);
public function columns(string $table): array;
public function tableExists(string $table): bool;
```

#### `Ritmo\Infrastructure\Db\Schema\Table` (final class)

```php
public readonly array $columns;
public readonly array $indexes;
public readonly string $name;
public function __construct(string $name, array $columns, array $indexes = []);
public function columnNames(): array;
```

#### `Ritmo\Infrastructure\Db\Statement` (final class)

```php
public static function identifier(string $name): string;
public static function normalize(array $params): array;
public static function placeholders(string $sql): int;
public static function insert(string $table, array $row, string $verb = 'INSERT INTO'): array;
public static function insertIgnore(string $dialect, string $table, array $row): array;
public static function upsert(string $dialect, string $table, array $row, array $uniqueColumns): array;
public static function update(string $table, array $values, array $where): array;
public static function delete(string $table, array $where): array;
public static function floatText(float $value): string;
```

#### `Ritmo\Infrastructure\Db\WpdbDatabase` (final class implements Database)

```php
public function __construct(object $wpdb, string $namespace = 'ritmo_');
public function dialect(): string;
public function prefix(): string;
public function table(string $name): string;
public function charsetCollate(): string;
public function select(string $sql, array $params = []): array;
public function selectRow(string $sql, array $params = []): ?array;
public function selectValue(string $sql, array $params = []): ?string;
public function execute(string $sql, array $params = []): int;
public function insert(string $table, array $row): int;
public function insertIgnore(string $table, array $row): bool;
public function upsert(string $table, array $row, array $uniqueColumns): void;
public function update(string $table, array $values, array $where): int;
public function delete(string $table, array $where): int;
public function transaction(callable $work): mixed;
```

#### `Ritmo\Infrastructure\Http\CreditAwareHttpClient` (final class implements WriteHttpClient, HttpClient)

```php
public function __construct(HttpClient $inner, Clock $clock, Sleeper $sleeper, CreditHeaders $headers, float $minimumCredit = 5.0, float $maxWaitSeconds = 310.0, float $defaultRetryAfter = 60.0);
public function request(string $method, string $url, array $headers = [], ?string $body = null, int $timeout = 30): HttpResponse;
public function write(WriteToken $token, string $method, string $url, array $headers = [], ?string $body = null, int $timeout = 30): HttpResponse;
public function waits(): array;
```

#### `Ritmo\Infrastructure\Http\CreditHeaders` (final class)

```php
public readonly array $remaining;
public readonly array $resetsIn;
public readonly array $cost;
public function __construct(array $remaining, array $resetsIn, array $cost = []);
public static function fiveMinuteWindow(): self;
```

#### `Ritmo\Infrastructure\Http\CurlHttpClient` (final class implements HttpClient)

```php
public function __construct(string $userAgent = 'RitmoRevenue');
public function request(string $method, string $url, array $headers = [], ?string $body = null, int $timeout = 30): HttpResponse;
```

#### `Ritmo\Infrastructure\Http\DbQuotaLedger` (final class implements QuotaLedger)

```php
public function __construct(QuotaLogRepository $log);
public function record(string $provider, string $action, string $scope, bool $succeeded, DateTimeImmutable $at): void;
public function countSince(string $provider, string $action, string $scope, DateTimeImmutable $since): int;
public function countInWindow(string $provider, string $action, string $scope, int $windowSeconds, DateTimeImmutable $now): int;
public function opensAt(string $provider, string $action, string $scope, int $limit, int $windowSeconds, DateTimeImmutable $now): ?DateTimeImmutable;
```

#### `Ritmo\Infrastructure\Http\GuardedHttpClient` (final class implements WriteHttpClient, HttpClient)

```php
public function __construct(HttpClient $inner, ReadRouteAllowList $readRoutes, Logger $logger, ?WriteRouteAllowList $writeRoutes = null, ?WriteAuthority $authority = null);
public function request(string $method, string $url, array $headers = [], ?string $body = null, int $timeout = 30): HttpResponse;
public function write(WriteToken $token, string $method, string $url, array $headers = [], ?string $body = null, int $timeout = 30): HttpResponse;
public function blockedAttempts(): array;
```

#### `Ritmo\Infrastructure\Http\RateLimit` (final class)

```php
public readonly int $max;
public readonly int $windowSeconds;
public function __construct(int $max, int $windowSeconds);
public static function perMinute(int $max): self;
public static function perHour(int $max): self;
```

#### `Ritmo\Infrastructure\Http\RateLimiter` (final class)

```php
public function __construct(Clock $clock, Sleeper $sleeper, array $limits, float $maxWaitSeconds = 120.0);
public function waitTime(string $key): float;
public function tryAcquire(string $key): bool;
public function acquire(string $key): float;
public function used(string $key, int $windowSeconds): int;
```

#### `Ritmo\Infrastructure\Http\ReadRouteAllowList` (final class)

```php
public static function none(): self;
public function with(string $connector, string $method, string $host, string $path): self;
public function allows(string $method, string $host, string $path): bool;
public function routes(): array;
```

#### `Ritmo\Infrastructure\Http\RequestLine` (final class)

```php
public static function method(string $method): string;
public static function headerLines(array $headers): array;
```

#### `Ritmo\Infrastructure\Http\RequestTarget` (final class)

```php
public readonly string $scheme;
public readonly string $host;
public readonly string $path;
public static function fromUrl(string $url): self;
public function __toString(): string;
```

#### `Ritmo\Infrastructure\Http\ThrottledHttpClient` (final class implements WriteHttpClient, HttpClient)

```php
public function __construct(HttpClient $inner, RateLimiter $limiter, string $key);
public function request(string $method, string $url, array $headers = [], ?string $body = null, int $timeout = 30): HttpResponse;
public function write(WriteToken $token, string $method, string $url, array $headers = [], ?string $body = null, int $timeout = 30): HttpResponse;
```

#### `Ritmo\Infrastructure\Http\WpHttpClient` (final class implements HttpClient)

```php
public function __construct(string $userAgent = 'RitmoRevenue');
public function request(string $method, string $url, array $headers = [], ?string $body = null, int $timeout = 30): HttpResponse;
```

#### `Ritmo\Infrastructure\Http\WriteRouteAllowList` (final class)

```php
public static function none(): self;
public function with(string $connector, string $method, string $host, string $pattern): self;
public function withBase(string $connector, string $method, string $baseUrl, string $relativePattern): self;
public function allows(string $method, string $host, string $path): bool;
public function routes(): array;
```

#### `Ritmo\Infrastructure\Lock\DbLock` (final class)

```php
public function __construct(Database $db, Clock $clock);
public function acquire(string $key, int $ttlSeconds): ?LockHandle;
public function heartbeat(LockHandle $handle, int $ttlSeconds): bool;
public function release(LockHandle $handle): void;
public function holder(string $key): ?array;
public function isLocked(string $key): bool;
```

#### `Ritmo\Infrastructure\Lock\LockHandle` (final class)

```php
public readonly string $key;
public readonly string $owner;
public function __construct(string $key, string $owner);
```

#### `Ritmo\Infrastructure\Log\DbLogger` (final class implements Logger)

```php
public function __construct(Database $db, Clock $clock, LogSanitizer $sanitizer, string $minimumLevel = 'info');
public function log(string $level, string $message, array $context = []): void;
public function recent(int $limit = 100, string $minimumLevel = 'debug'): array;
public function purgeBefore(DateTimeImmutable $instant): int;
public function debug(string $message, array $context = []): void;
public function info(string $message, array $context = []): void;
public function warning(string $message, array $context = []): void;
public function error(string $message, array $context = []): void;
```

#### `Ritmo\Infrastructure\Log\LogLevel` (final class)

```php
public static function rank(string $level): int;
public static function reaches(string $level, string $minimum): bool;
```

#### `Ritmo\Infrastructure\Log\LogSanitizer` (final class)

```php
const REDACTED = '[redacted]';
const OMITTED = '[omitted]';
public function registerSecret(string $secret): void;
public function message(string $message): string;
public function context(array $context): array;
```

#### `Ritmo\Infrastructure\Log\LogsByLevel` (trait)

```php
public function debug(string $message, array $context = []): void;
public function info(string $message, array $context = []): void;
public function warning(string $message, array $context = []): void;
public function error(string $message, array $context = []): void;
```

#### `Ritmo\Infrastructure\Log\MemoryLogger` (final class implements Logger)

```php
public function __construct(?LogSanitizer $sanitizer = null, string $minimumLevel = 'debug');
public function log(string $level, string $message, array $context = []): void;
public function entries(?string $level = null): array;
public function debug(string $message, array $context = []): void;
public function info(string $message, array $context = []): void;
public function warning(string $message, array $context = []): void;
public function error(string $message, array $context = []): void;
```

#### `Ritmo\Infrastructure\Schedule\AlertLedger` (final class)

```php
public function __construct(Database $db);
public function latestByKey(string $dedupeKey): ?AlertRecord;
public function find(int $id): ?AlertRecord;
public function insert(AlertDraft $draft, ?int $runId, DateTimeImmutable $now): AlertRecord;
public function refresh(AlertRecord $alert, AlertDraft $draft, ?int $runId, DateTimeImmutable $now): AlertRecord;
public function active(string $propertyId, array $codes): array;
public function since(string $propertyId, string $code, DateTimeImmutable $since): array;
public function acknowledge(int $id, int $userId, DateTimeImmutable $now): void;
public function resolve(int $id, ?int $userId, DateTimeImmutable $now): void;
public function markNotified(int $id, DateTimeImmutable $now): void;
public function notifiedSince(string $propertyId, DateTimeImmutable $since): int;
public function pendingDigest(string $propertyId): array;
public function markDigested(array $ids, DateTimeImmutable $now): void;
```

#### `Ritmo\Infrastructure\Schedule\BookingMovementRepository` (final class)

```php
public function __construct(Database $db);
public function lastVersions(string $propertyId, string $pms, array $pmsRefs): array;
public function record(string $propertyId, string $pms, array $versions, DateTimeImmutable $detectedAt, ?int $runId): void;
public function purgeDepartedBefore(DateTimeImmutable $date): int;
```

#### `Ritmo\Infrastructure\Schedule\Mailer` (interface)

```php
public function send(array $to, string $subject, string $body): bool;
```

#### `Ritmo\Infrastructure\Schedule\OperationStateRepository` (final class)

```php
public function __construct(Database $db, Clock $clock);
public function get(string $propertyId): OperationState;
public function pause(string $propertyId, ?DateTimeImmutable $until, int $userId, string $reason): void;
public function resume(string $propertyId): void;
public function setMovementCursor(string $propertyId, DateTimeImmutable $cursor): void;
public function setSlotsConsideredUntil(string $propertyId, DateTimeImmutable $instant): void;
```

#### `Ritmo\Infrastructure\Schedule\RecipientDirectory` (interface)

```php
public function addresses(): array;
```

#### `Ritmo\Infrastructure\Schedule\ScheduledRunRepository` (final class)

```php
public function __construct(Database $db, Clock $clock);
public function materialise(string $propertyId, Slot $slot): bool;
public function due(string $propertyId, DateTimeImmutable $now): array;
public function oldestDueAt(?string $propertyId, DateTimeImmutable $now): ?DateTimeImmutable;
public function running(string $propertyId): array;
public function find(int $id): ?SlotRecord;
public function findByKey(string $propertyId, Slot $slot): ?SlotRecord;
public function forRun(int $runId): ?SlotRecord;
public function started(int $id, ?int $runId, DateTimeImmutable $startedAt, int $delaySeconds, bool $promoted, SlotStatus $status): void;
public function close(int $id, SlotStatus $status, DateTimeImmutable $at, string $note = ''): void;
public function latest(string $propertyId, int $limit = 50): array;
public function purgeBefore(DateTimeImmutable $instant): int;
```

#### `Ritmo\Infrastructure\Schedule\WpCronTrigger` (final class)

```php
const HOOK = 'ritmo_tick';
const INTERVAL = 'ritmo_five_minutes';
const INTERVAL_SECONDS = 300;
public static function withInterval(array $schedules): array;
public static function schedule(int $firstAt): void;
public static function spawnNow(): void;
public static function unschedule(): void;
public static function state(): array;
```

#### `Ritmo\Infrastructure\Schedule\WpMailer` (final class implements Mailer)

```php
public function send(array $to, string $subject, string $body): bool;
```

#### `Ritmo\Infrastructure\Schedule\WpRecipientDirectory` (final class implements RecipientDirectory)

```php
const CAPABILITY = 'ritmo_approve';
const OPT_OUT_META = 'ritmo_alert_email';
const EXTRA_KEY = 'notifications.extra_addresses';
public function __construct(KeyValueStore $settings);
public function addresses(): array;
```

#### `Ritmo\Infrastructure\Secrets\SecretStatus` (final class)

```php
public readonly bool $configured;
public readonly ?DateTimeImmutable $updatedAt;
public function __construct(bool $configured, ?DateTimeImmutable $updatedAt = null);
```

#### `Ritmo\Infrastructure\Secrets\SecretUnavailable` (final class extends RuntimeException)

```php
// no public members
```

#### `Ritmo\Infrastructure\Secrets\SodiumVault` (final class)

```php
const MIN_CONFIGURED_KEY_LENGTH = 32;
const PROTECTION_CONFIGURED_KEY = 'configured_key';
const PROTECTION_SITE_SALTS = 'site_salts';
const PROTECTION_DATABASE_ONLY = 'database_only';
const PROTECTION_UNAVAILABLE = 'unavailable';
public function __construct(KeyValueStore $store, Clock $clock, ?string $configuredKey = null, ?string $siteSecret = null);
public static function forSite(KeyValueStore $store, Clock $clock): self;
public static function configuredKeyProblem(?string $configuredKey): ?string;
public function problem(): ?string;
public function protection(): string;
public function install(): void;
public function upgrade(): int;
public function put(string $name, string $secret): void;
public function reveal(string $name): string;
public function has(string $name): bool;
public function status(string $name): SecretStatus;
public function forget(string $name): void;
public function names(): array;
public function rotate(?string $previousConfiguredKey = null): int;
public function __debugInfo(): array;
public function __serialize(): array;
```

#### `Ritmo\Infrastructure\Store\ArrayStore` (final class implements KeyValueStore)

```php
public function __construct(array $values = []);
public function get(string $key, mixed $default = null): mixed;
public function getFresh(string $key, mixed $default = null): mixed;
public function has(string $key): bool;
public function set(string $key, mixed $value): void;
public function delete(string $key): void;
public function all(): array;
```

#### `Ritmo\Infrastructure\Store\JsonValue` (final class)

```php
public static function encode(string $key, mixed $value): string;
```

#### `Ritmo\Infrastructure\Store\OptionsStore` (final class implements KeyValueStore)

```php
public function __construct(string $prefix = 'ritmo_');
public function get(string $key, mixed $default = null): mixed;
public function getFresh(string $key, mixed $default = null): mixed;
public function has(string $key): bool;
public function set(string $key, mixed $value): void;
public function delete(string $key): void;
```

#### `Ritmo\Infrastructure\Support\Sleeper` (interface)

```php
public function sleep(float $seconds): void;
```

#### `Ritmo\Infrastructure\Support\SystemSleeper` (final class implements Sleeper)

```php
public function sleep(float $seconds): void;
```

<!-- END GENERATED SIGNATURES -->
