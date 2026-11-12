# Ritmo Revenue: infrastructure

What `src/Infrastructure` offers to the application, the panel and the composition root. The conventions are in [ARCHITECTURE.md](ARCHITECTURE.md), the contracts it implements in [CONTRACTS.md](CONTRACTS.md). Part 2 is the exact signature of every public member, generated from the code; a test fails when it is stale.

## Part 1. Guide

### 1.1 Wiring

```php
$clock = new SystemClock();
$store = new OptionsStore();                    // key "db_version" is the option "ritmo_db_version"
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

### 1.2 Read-only guard (milestone M1)

`GuardedHttpClient` lets only `GET` leave the site. Any other method, to any host, ends in `WriteBlocked` before the wrapped client is called, is logged as a warning (method, host and path only) and is listed by `blockedAttempts()`. Requests with a method-override header are blocked too; a malformed method, URL or header is an `InvalidArgumentException`. The clients never follow redirects.

The one exception is `ReadRouteAllowList`: a `POST` whose host and **exact** path were added with `->with($connector, 'POST', $host, $path)` passes. Only `POST` can be listed. **The list is empty unless the composition root adds to it.** Whether the pricing system's calendar read (a `POST`) goes on the list is the open product-safety decision of ARCHITECTURE.md section 6: until the owner decides, pass `ReadRouteAllowList::none()`.

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

`bookings` stores what `BookingRecord` carries and nothing else; a test lists its columns and fails when one that could identify a guest is added. `relations`, `periods`, `price_factors`, `competitors`, `orders`, `write_ops`, `change_log` and `pending_items` have no repository yet: they belong to later milestones.

### 1.4 Repositories

All in `Ritmo\Infrastructure\Db\Repository`, built with the `Database` (and the `Clock` when they stamp rows). They take and return the domain DTOs where one exists; the others return arrays with snake_case keys and typed values (dates as `DateTimeImmutable`, enums as enums), documented on each method.

| Repository | Notes |
|---|---|
| `PropertyRepository`, `RoomTypeRepository` | `save()` creates or replaces by id/code. Lookups are exact, and a code that differs from an existing one only in case is refused (MySQL and SQLite disagree on case). `RoomTypeRepository::pmsMap()` is the room map a PMS connector is built with. |
| `RuleSetRepository` | `save()` writes a new version and returns its number; saving rules equal to the current version returns the current number. No method edits or deletes a version. |
| `BookingRepository` | `upsert($pms, $booking, $syncedAt)` is keyed by connector id + PMS reference. `overlapping($from, $to)` returns every status, like `PmsConnector::readBookingsByStay()`. `latestModification($pms)` is the cursor for incremental reads. |
| `InventorySnapshotRepository` | Keeps every capture; `latest($room, $from, $to, $asOf)` answers with the calendar as known at an instant. |
| `MarketAnchorRepository` | One anchor per property and night; `range()` has the shape of `MarketDataProvider::readAnchors()`. |
| `PriceSampleRepository` | Samples are only added. |
| `RunRepository`, `PlanItemRepository` | `start()` / `finish()`; `addAll($runId, $items)` stores a whole plan or nothing. |
| `AlertRepository` | `raise()` with a `$dedupeKey` refreshes the open alert of the same key instead of repeating it. |
| `StepLedgerRepository` | Every pace step of a night; `lastStep()` for the cadence rule, `markReachedChannel()` after a push. |
| `QuotaLogRepository` | The rows behind `DbQuotaLedger`. |

### 1.5 Secrets

`SodiumVault` encrypts with `sodium_crypto_secretbox`, one subkey per entry. For the interface there is only `status($name)`: configured or not, the last four characters (none for a secret shorter than 12) and when it was saved. `reveal($name)` is for the connector that sends the secret; its result must never be shown, logged or returned by an endpoint. Register revealed secrets with `LogSanitizer::registerSecret()` so that they are masked wherever they appear.

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

Generated from the code. Do not edit between the markers; run `php tests/Unit/Infrastructure/Support/sync-doc.php`.

<!-- BEGIN GENERATED SIGNATURES (php tests/Unit/Infrastructure/Support/sync-doc.php) -->

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

#### `Ritmo\Infrastructure\Db\DatabaseException` (final class extends RuntimeException implements )

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

#### `Ritmo\Infrastructure\Db\Repository\CodeLookup` (final class)

```php
public static function id(Database $db, string $table, string $code): ?int;
```

#### `Ritmo\Infrastructure\Db\Repository\InventorySnapshotRepository` (final class)

```php
public function __construct(Database $db);
public function store(string $roomCode, array $nights, DateTimeImmutable $capturedAt, ?int $runId = null): int;
public function latest(string $roomCode, DateTimeImmutable $from, DateTimeImmutable $to, ?DateTimeImmutable $asOf = null): array;
public function lastCapturedAt(string $roomCode): ?DateTimeImmutable;
public function purgeCapturedBefore(DateTimeImmutable $instant): int;
```

#### `Ritmo\Infrastructure\Db\Repository\MarketAnchorRepository` (final class)

```php
public function __construct(Database $db, Clock $clock);
public function save(string $propertyId, MarketAnchor $anchor): void;
public function saveMany(string $propertyId, iterable $anchors): void;
public function find(string $propertyId, DateTimeImmutable $night): ?MarketAnchor;
public function range(string $propertyId, DateTimeImmutable $from, DateTimeImmutable $to): array;
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
public function history(int $limit = 50): array;
```

#### `Ritmo\Infrastructure\Db\Repository\RunRepository` (final class)

```php
const RUNNING = 'running';
const COMPLETED = 'completed';
const FAILED = 'failed';
public function __construct(Database $db);
public function start(string $propertyId, string $kind, PropertyMode $mode, ?int $ruleSetVersion, DateTimeImmutable $startedAt): int;
public function finish(int $runId, string $status, array $summary, DateTimeImmutable $finishedAt, ?string $error = null): void;
public function find(int $runId): ?array;
public function latest(?string $propertyId = null, int $limit = 20): array;
```

#### `Ritmo\Infrastructure\Db\Repository\StepLedgerRepository` (final class)

```php
const UP = 'up';
const DOWN = 'down';
public function __construct(Database $db);
public function record(string $propertyId, string $roomCode, DateTimeImmutable $night, string $direction, float $from, float $to, DateTimeImmutable $recordedAt, string $paceStatus = '', ?int $unitsSold = null, ?int $unitsTotal = null, string $countBasis = 'room', ?int $runId = null): int;
public function lastStep(string $roomCode, DateTimeImmutable $night): ?array;
public function forNight(string $roomCode, DateTimeImmutable $night): array;
public function markReachedChannel(string $roomCode, DateTimeImmutable $pushedAt): int;
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

#### `Ritmo\Infrastructure\Http\CreditAwareHttpClient` (final class implements HttpClient)

```php
public function __construct(HttpClient $inner, Clock $clock, Sleeper $sleeper, CreditHeaders $headers, float $minimumCredit = 5.0, float $maxWaitSeconds = 310.0, float $defaultRetryAfter = 60.0);
public function request(string $method, string $url, array $headers = [], ?string $body = null, int $timeout = 30): HttpResponse;
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

#### `Ritmo\Infrastructure\Http\GuardedHttpClient` (final class implements HttpClient)

```php
public function __construct(HttpClient $inner, ReadRouteAllowList $readRoutes, Logger $logger);
public function request(string $method, string $url, array $headers = [], ?string $body = null, int $timeout = 30): HttpResponse;
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

#### `Ritmo\Infrastructure\Http\RequestTarget` (final class implements )

```php
public readonly string $scheme;
public readonly string $host;
public readonly string $path;
public static function fromUrl(string $url): self;
public function __toString(): string;
```

#### `Ritmo\Infrastructure\Http\ThrottledHttpClient` (final class implements HttpClient)

```php
public function __construct(HttpClient $inner, RateLimiter $limiter, string $key);
public function request(string $method, string $url, array $headers = [], ?string $body = null, int $timeout = 30): HttpResponse;
```

#### `Ritmo\Infrastructure\Http\WpHttpClient` (final class implements HttpClient)

```php
public function __construct(string $userAgent = 'RitmoRevenue');
public function request(string $method, string $url, array $headers = [], ?string $body = null, int $timeout = 30): HttpResponse;
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

#### `Ritmo\Infrastructure\Secrets\SecretStatus` (final class)

```php
public readonly bool $configured;
public readonly string $lastFour;
public readonly ?DateTimeImmutable $updatedAt;
public function __construct(bool $configured, string $lastFour = '', ?DateTimeImmutable $updatedAt = null);
```

#### `Ritmo\Infrastructure\Secrets\SecretUnavailable` (final class extends RuntimeException implements )

```php
// no public members
```

#### `Ritmo\Infrastructure\Secrets\SodiumVault` (final class)

```php
const MIN_CONFIGURED_KEY_LENGTH = 32;
public function __construct(KeyValueStore $store, Clock $clock, ?string $configuredKey = null);
public static function forSite(KeyValueStore $store, Clock $clock): self;
public function install(): void;
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
