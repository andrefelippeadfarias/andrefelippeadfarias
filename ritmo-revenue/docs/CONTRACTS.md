# Ritmo Revenue: shared contracts

Everything other modules build on: support classes, DTOs, interfaces, the configuration model and the test helpers. Part 1 explains the meaning and the shapes that a signature cannot show. Part 2 is the **exact signature of every public member**, generated from the code by `php bin/sync-generated.php`; a test fails when it is stale, so it can be trusted.

Conventions (dates, money, errors, logging) are in [ARCHITECTURE.md](ARCHITECTURE.md), section 3. The three that matter most here:

- A **date-only value** is a `DateTimeImmutable` at 00:00:00 UTC (`Days::date('2026-11-11')`). An **instant** is a `DateTimeImmutable` in UTC.
- **`$from` and `$to` are nights, both inclusive**, everywhere.
- Amounts are floats in the property currency; round only with `Num`.

## Part 1. Guide

### 1.1 `Ritmo\Domain\Support`

| Type | Use |
|---|---|
| `Clock` | `now()` is the current instant in UTC. `today($timezone)` is the local calendar date as a date-only value. Inject it; never read the system clock in the domain. |
| `SystemClock`, `FixedClock` | Production and test clocks. `new FixedClock('2026-11-11T19:53:00-03:00')` accepts only absolute ISO 8601 text or a `DateTimeInterface`. |
| `Days` | `date()` (strict `YYYY-MM-DD`), `key()` (back to `YYYY-MM-DD`), `normalize()`, `localDate()`, `add()`, `diff($from, $to)` (signed days; `D` of the pace rules is `Days::diff($today, $night)`), `weekday()` (Monday = 0 ... Sunday = 6, as Python). |
| `DateRange` | Inclusive range of nights. `new DateRange($first, $last)`, `DateRange::nights($first, $count)`, `DateRange::stay($arrival, $departure)` (arrival to the day before departure), `fromStrings()`. Iterating yields `'YYYY-MM-DD' => DateTimeImmutable`. |
| `Num` | The only rounding. See below. |

`Num` and the reference engine:

| `Num` | Python reference | Notes |
|---|---|---|
| `roundHalfEven($x, $decimals = 0): float` | `round(x, n)` | Ties to even on the exact binary value: `round(2.5) = 2`, `round(2.675, 2) = 2.67`. PHP's `round()` differs. |
| `roundInt($x): int` | `round(x)` | |
| `ceilEps($x): int` | `math.ceil(x - 1e-9)` | |
| `floorEps($x): int` | `math.floor(x + 1e-9)` | |
| `ceilInt($x): int`, `floorInt($x): int` | `math.ceil(x)`, `math.floor(x)` | No tolerance: how the pace engine rounds a rising and a falling price. |
| `clamp($x, $min, $max): float` | `max(min_, min(max_, x))` | |
| `floorToPercent($amount, $base): int` | `validar_plano.piso_pct` | Floor as a whole % over the base, rounded **up**. -31 means base x 0.69. |
| `ceilingToPercent($amount, $base): int` | `validar_plano.teto_pct` | Ceiling as a whole % over the base, rounded **down**. |
| `percentToAmount($percent, $base, $decimals = 0): float` | `validar_plano.em_reais` (0 decimals) | With 2 decimals and the two functions above it is `pace.efetivo`: `Num::percentToAmount(Num::floorToPercent($amount, $base), $base, 2)`. |

Every `Num` function throws `\InvalidArgumentException` on NaN, infinity or a base that is not positive.

### 1.2 `Ritmo\Domain\Model`

Immutable DTOs (`final`, every property `readonly`) and backed enums. Constructors validate and throw `\InvalidArgumentException`. Date parameters are normalised to date-only values.

| Type | Notes |
|---|---|
| `Property` | `id` is the stable key chosen at setup (a code, unique per site); rule tables reference properties by it. `mode` defaults to `PropertyMode::Shadow`. `occupancyGoals` (`array<int window days, float percent>`) is the property's own override and may be empty: resolve with `RuleSet::occupancyGoalsFor($property)`. `tier` (slug, default `standard`) selects the target-percentile table. `fromArray()`/`toArray()` use snake_case keys (`occupancy_goals`). |
| `RoomType` | `propertyId` links it to its property (first constructor argument). `code` is unique per site. `pmsRef` and `pricingRef` may be `''` while unmapped. `base`, `min`, `max` are the listing values in the sent plan as configured in the plugin; `referenceMin` is the owner's reference minimum. `tags` are slugs (`hasTag()`, `hasAllTags()`). |
| `InventoryNight` | One PMS calendar night of a room: `available` (negative when the night is overbooked by that many units; connectors pass the PMS number through), `minStay`, `maxStay`, `restriction` (`Restriction`), `pmsPrice` (null unless prices were read). |
| `PriceNight` | One night of a listing in the pricing system: `price`, `minPrice`, `maxPrice` (floor and ceiling in force, null when unknown), `minStay`, `sold`, `total`, `hasDateOverride`. `isSoldOut()` is `total > 0 && sold >= total`. Optional, as the pricing system reports them: `userPrice` (the price it holds as in use; null when it reports none), `uncustomizedPrice` (its algorithm before the account's customisations) and `unbookable`. |
| `DateOverride` | A date-specific override. `minPercent`/`maxPercent` are percentages over the listing base (what the plugin writes). `checkinMask`/`checkoutMask` are 7 characters `0`/`1`, Monday to Sunday. Overrides made by hand may carry `price` + `priceType` (`DateOverride::PRICE_FIXED` or `PRICE_PERCENT`) or absolute `minFixed`/`maxFixed`; these optional fields exist so reading never loses what is in force. |
| `BookingRecord` | No guest name, contact or free text, by design; a test fails if such a field is added. `roomCode` is null when the PMS room is not mapped (`roomPmsRef` always holds the PMS room id). `units` is how many units the booking takes. `nights()` returns its `DateRange`. |
| `BookingStatus` | `confirmed`, `new`, `request`, `cancelled`, `blocked`, `inquiry`. `occupies()` is true for `confirmed` and `new`. Each PMS connector maps its own statuses. |
| `SyncStatus` | `pushEnabled`, `lastPushedAt`, `lastRefreshedAt` (instants, nullable). |
| `ListingInfo` | A listing as the pricing system reports it: `ref`, `name`, `min`, `base`, `max`, `currency` (nullable: live values) and `sync`. Returned by `PricingConnector::listListings()`. |
| `MarketAnchor` | Percentiles of the **public** nightly price of comparable competitors: `p25`, `p50`, `p75`, `p90`, `max` (ascending, enforced), `n` (sample size), `capturedAt` (date-only, nullable), `origin` (`AnchorOrigin::Measured` or `Fallback`). |
| `NightType`, `PaceStatus`, `PricePlan`, `PropertyMode`, `Restriction`, `AnchorOrigin` | Backed enums; values in Part 2. Note `PricePlan::PublicRate` has the value `'public'`. |
| `Identifier` | `isCode()` (property ids, room codes) and `isSlug()` (tiers, tags). |

### 1.3 `Ritmo\Contracts`

All `$from`/`$to` are nights, both inclusive. Read methods throw `ConnectorException` (or `RateLimited`, `AuthFailed`). `testConnection()` never throws.

**`PmsConnector`**

| Method | Returns |
|---|---|
| `readBookingsByStay($from, $to)` | `list<BookingRecord>`: bookings of **every status** with at least one night in the range. |
| `readBookingsModifiedSince($instant)` | `list<BookingRecord>`: bookings of every status created or modified at or after the instant. |
| `readInventory($roomRefs, $from, $to, $withPrices = false)` | `array<string pmsRoomRef, list<InventoryNight>>`, nights ascending; nights the PMS does not report are absent. |
| `writeCalendar(WriteToken $token, array $changes)` | Throws `NotAvailableInThisMilestone` in M1. |

A PMS connector is built with the room map of the site (PMS room id to room type code) so it can fill `BookingRecord::$roomCode`.

**`PricingConnector`**

| Method | Returns |
|---|---|
| `listListings()` | `list<ListingInfo>`: every listing with min/base/max and push state. |
| `readPrices($listingRef, $from, $to)` | `list<PriceNight>`, ascending. |
| `readOverrides($listingRef, $from, $to)` | `list<DateOverride>`, ascending; nights without an override are absent. |
| `requestRecalculation`, `writeOverrides`, `deleteOverrides`, `updateListing` | Take a `WriteToken` first; throw `NotAvailableInThisMilestone` in M1. |

**Write methods in M1.** Implementations `use Ritmo\Contracts\Support\PmsWritesUnavailable;` or `PricingWritesUnavailable;` and do not write these methods themselves. `WriteToken` cannot be constructed (private constructor, no factory). The array shapes of `$changes`, `$overrides` and `$fields` are provisional until the milestone that enables writing.

**Other contracts**

| Type | Notes |
|---|---|
| `HttpClient::request($method, $url, $headers = [], $body = null, $timeout = 30): HttpResponse` | The only door to the network. Returns 4xx and 5xx responses (the connector interprets them). Throws `ConnectorException` (retryable) when there is no response, `WriteBlocked` when a mutating request is stopped. `$body` is already encoded. |
| `HttpResponse` | `status()`, `isSuccess()` (2xx), `header($name)` (case-insensitive), `headers()` (lowercase names), `body()`, `json()` (array; throws `ConnectorException` when the body is not a JSON object or list). |
| `ConnectionStatus` | `ok`, `message`, `details` (scalars safe to show). |
| `MarketDataProvider::readAnchors($propertyId, $from, $to)` | `array<'YYYY-MM-DD', MarketAnchor>`, ascending; nights without data are absent. |
| `LicenceProvider::check($licenceKey, $siteUrl): LicenceState` | Never throws; an unreachable provider answers `LicenceState::UNREACHABLE`. |
| `AiProvider::complete($system, $messages, $maxTokens): string` | `$messages` is `list<array{role: 'user'|'assistant', content: string}>`. Text only; no guest data in prompts. |
| `KeyValueStore` | `get($key, $default = null)`, `getFresh($key, $default = null)` (read past any cache of the request, so a value stored by another request or process is seen; for the emergency switch), `has`, `set`, `delete`. JSON-encodable values. Not for secrets. |
| `QuotaLedger` | `record($provider, $action, $scope, $succeeded, $at)` and `countSince($provider, $action, $scope, $since)`. A failed call still spends quota. `$scope` is a listing ref or `''` for the account. |
| `Logger` | `log($level, $message, $context = [])` plus `debug`, `info`, `warning`, `error`. Levels are the constants `Logger::DEBUG` ... `Logger::ERROR`. |

**Exceptions** (`Ritmo\Contracts\Exception`)

| Exception | Extends | Meaning |
|---|---|---|
| `ConnectorException` | `\RuntimeException` | Any failure talking to an external system. `provider()`, `httpStatus()`, `isRetryable()`. |
| `RateLimited` | `ConnectorException` | Rate limit or quota reached. `retryAfterSeconds()`. Stop the batch. |
| `AuthFailed` | `ConnectorException` | Credentials rejected. Never retried. |
| `NotAvailableInThisMilestone` | `\LogicException` | A write method was reached while the plugin is read-only: a bug. |
| `WriteBlocked` | `\RuntimeException` | A mutating request was stopped before leaving the site. |

### 1.4 `Ritmo\Domain\Config`

```php
$schema = Schema::ruleSet();                       // every configurable field
$errors = (new RuleSetValidator())->validate($a);  // list<ValidationError>; [] means valid
$rules  = RuleSet::fromArray($a);                  // strict; throws InvalidConfig
$rules  = RuleSet::defaults();                     // the neutral rule set
$preset = (new PresetLoader())->fromFile($path);   // Preset {name, description, properties, roomTypes, rules}
```

- **`Schema`**: `fields()` (key => `Field`, parents first), `field($key)`, `children($key)` (`''` for the top level), `keyForPath('calendar.holidays.2.end')` gives `'calendar.holidays.*.end'`, `defaults()`, `withDefaults($data)` (fills missing fields that have a default, at any depth; keeps everything else). In a key, `*` stands for any map key or list index.
- **`Field`**: `key`, `type` (`FieldType`), `label`, `help`, `default`, `required` (no default), `nullable`, `min`, `max` (value, text length or item count by type), `exclusiveMin`, `enum`, `locked` (fixed by the product), `pattern`, `requiredKeys`, `unique`, `unit`.
- **`RuleSetValidator::validate()`** is strict: every field present, unknown keys rejected, no type coercion. Each `ValidationError` has `path` (`pace.curves.weekday.7`), `code` (constants of `ValidationError`) and `message` (English, without the path).
- **`RuleSet`** is immutable and canonical (schema order, numbers as floats, map keys sorted). `hash()` identifies a version. `get('pace.band')` reads any path.
- **`PresetLoader`** applies `withDefaults()` to the rules, validates everything and throws `InvalidConfig` with paths such as `properties.0.room_types.2` or `rules.pace.band`. It also checks that property tiers, factor tables and room exceptions reference things that exist.

Typed accessors of `RuleSet` and the rule each one reads:

| Accessor | Rule key | Reference engine |
|---|---|---|
| `occupancyGoals(): array<int, float>`; `occupancyGoalsFor(Property)` | `occupancy.goals.{7,15,30,45,60}` | `metas` |
| `weekendDays(): list<int>` | `calendar.weekend_days` | Friday and Saturday nights, fixed in code |
| `holidays(): list<Holiday>`; `holidayOn($night): ?Holiday` (first match) | `calendar.holidays.*` | `feriados`, `comum.periodo_feriado` |
| `nightType($night): NightType` | the period's `night_type`, else weekend days | `ancora_mercado.tipo_noite` |
| `paceCurve(NightType): array<int days, float percent>` (farthest first) | `pace.curves.<type>.{60,30,14,7,3,1,0}` | `pace.curvas` |
| `paceBand()`, `paceVeryBehind()` | `pace.band`, `pace.very_behind` | `banda`, `muito_atras` |
| `zeroSoldDays(NightType): int` | `pace.zero_sold_days.<type>` | 7 / 14 / 14, fixed in `status_da_noite` |
| `groupPaceMaxUnits(): int`, `groupPaceRoomTags(): list<string>` | `pace.group_pace.{max_units,room_tags}` | rooms of 1 or 2 units read by `ocupacao_pousada`, fixed in `_plano` (0 = off) |
| `targetPercentiles($tier): array`; `targetPercentile($tier, PaceStatus, $daysOut): float` | `pace.target_percentile.<tier>.*`, `pace.ahead_near_days` | `pace.percentil_alvo` (golden-tested) |
| `aheadNearDays()`, `stepStrength()` | `pace.ahead_near_days`, `pace.k` | `frente_perto_dias`, `k` |
| `maxStepPercent($daysOut): float` | `pace.max_step.*` (`min_days`, `percent`) | `pace.pmax` (golden-tested) |
| `minSampleSize()`, `maxSampleAgeDays()`, `tolerance()`, `protectionPercentile()` | `pace.min_n`, `pace.max_age_days`, `pace.tolerance`, `pace.protection_percentile` | `min_n`, `idade_max_dias`, `tolerancia`, `protecao_percentil` |
| `referenceMinFraction()` | `floors.fraction_of_reference_min` | `fracao_min_referencia` |
| `ownerFloors(): list<FloorRule>` | `floors.owner.*` | owner floors per night type |
| `qualityFloor(): FloorRule`, `holidayFloor(): FloorRule` | `floors.quality_floor`, `floors.holiday_floor` | quality standard, holiday safety floor |
| `priceFactor($propertyId, PricePlan, NightType, ?$roomCode = null): float` | `price_factors.room_overrides.*`, then `price_factors.properties.<id>`, then `.properties.*` | public and discounted factors |
| `weeklyListingChangeMax()`, `neverRaiseToday()` (always true) | `safety.*` | weekly limit of a listing |

`FloorRule` is `{amount, pricePlan, nightTypes, roomTags}`; `appliesTo(NightType, RoomType)` is true when the amount is above zero, the night type is listed and the room has every tag. To convert a floor to the sent plan: `amount / $rules->priceFactor($propertyId, $rule->pricePlan, $nightType, $room->code)`. `Holiday` is `{name, nights: DateRange, nightType, minStay, floor}`; its `floor` is in the price plan of `holidayFloor()`.

**The rule set array** (the neutral defaults; `presets/default.json` holds exactly this under `rules`):

```json
{
  "occupancy": {"goals": {"7": 60, "15": 45, "30": 30, "45": 20, "60": 10}},
  "calendar": {
    "weekend_days": [4, 5],
    "holidays": [{"name": "…", "start": "YYYY-MM-DD", "end": "YYYY-MM-DD", "night_type": "holiday", "min_stay": null, "floor": null}]
  },
  "pace": {
    "curves": {"weekday": {"60": 5, "30": 10, "14": 20, "7": 35, "3": 50, "1": 65, "0": 100}, "weekend": {"…": 0}, "holiday": {"…": 0}},
    "band": 15, "very_behind": 25,
    "zero_sold_days": {"weekday": 5, "weekend": 10, "holiday": 14},
    "group_pace": {"max_units": 0, "room_tags": []},
    "target_percentile": {"standard": {"ahead_near": 70, "ahead": 60, "on_pace": 50, "behind": 40, "very_behind": 30}, "premium": {"…": 0}},
    "ahead_near_days": 7, "k": 0.5,
    "max_step": [{"min_days": 30, "percent": 5}, {"min_days": 8, "percent": 10}, {"min_days": 0, "percent": 15}],
    "min_n": 5, "max_age_days": 14, "tolerance": 0.02, "protection_percentile": 85
  },
  "floors": {
    "fraction_of_reference_min": 0.75,
    "owner": [{"night_type": "weekend", "amount": 0, "price_plan": "discounted", "room_tags": []}],
    "quality_floor": {"amount": 0, "price_plan": "sent", "night_types": ["weekend", "holiday"], "room_tags": []},
    "holiday_floor": {"amount": 0, "price_plan": "sent"}
  },
  "price_factors": {
    "properties": {"*": {"public": {"weekday": 1, "weekend": 1, "holiday": 1}, "discounted": {"weekday": 1, "weekend": 1, "holiday": 1}}},
    "room_overrides": [{"room": "CODE", "price_plan": "discounted", "night_type": "holiday", "factor": 1}]
  },
  "safety": {"weekly_listing_change_max": 0.1, "never_raise_today": true}
}
```

(`holidays`, `owner` and `room_overrides` are empty lists by default; one item is shown for its shape. The tier `standard` and the property entry `*` are required. `never_raise_today` is locked to `true`.)

**The preset file**

```json
{
  "format": "ritmo-revenue-preset", "format_version": 1,
  "name": "…", "description": "…",
  "properties": [{
    "id": "main", "name": "…", "timezone": "Europe/Lisbon", "currency": "EUR",
    "mode": "shadow", "tier": "standard", "occupancy_goals": {},
    "room_types": [{"code": "Deluxe", "name": "…", "units": 3, "pms_ref": "", "pricing_ref": "",
                    "base": 900, "min": 600, "max": 4000, "reference_min": 550, "tags": ["tub"]}]
  }],
  "rules": {}
}
```

### 1.5 Test helpers (`Ritmo\Tests\Support`)

| Helper | Use |
|---|---|
| `Fixtures::path('golden/…')`, `Fixtures::json(…)`, `Fixtures::pluginRoot()` | Paths and decoded fixture files. |
| `ContractsDoc::render($document)`, `current()`, `sync()`, `ContractsDoc::DOCUMENTS` | The generated signature references of the module documents. |
| `tests/Fakes/*` | The doubles every module shares (clock, sleeper, HTTP client, logger, quota ledger, in-memory connectors, connector source, simulator server); listed in CONNECTORS.md, section 6. |
| `Fixtures::parityPreset(): Preset`, `Fixtures::parityRules(): RuleSet` | The reference engine's configuration: two properties (one in the tier `premium`, one in `standard`) and 7 room types. Compute golden cases from these; get the property id or tier of a golden case with `Golden::propertyId()` / `Golden::tier()`. |
| `Golden::cases('pace.curva')` | `list<array{input: array, expected: mixed}>` of a level-1 golden file. |
| `Golden::nightType('fds')`, `Golden::paceStatus('frente')`, `Golden::propertyId($pousada)`, `Golden::tier($pousada)` | Vocabulary of the reference engine to plugin types. |
| `Golden::AMOUNT_TOLERANCE` (0.01), `Golden::NAN` (`'NaN'`) | Comparison rules. |

Level-1 golden files and where their PHP port lives:

| Golden file | Input keys | PHP |
|---|---|---|
| `validar_plano.piso_pct`, `teto_pct` | `base`, `reais` | `Num::floorToPercent`, `Num::ceilingToPercent` (tested) |
| `validar_plano.em_reais` | `base`, `pct` | `Num::percentToAmount` (tested) |
| `pace.efetivo` | `curto`, `base`, `reais`, `para_baixo` | `Num` composition (tested) |
| `builtins.round` | `value`, `decimals` | `Num::roundHalfEven` (tested) |
| `pace.pmax` | `dias` | `RuleSet::maxStepPercent` (tested) |
| `pace.percentil_alvo` | `pousada`, `status`, `dias` | `RuleSet::targetPercentile` (tested) |
| `ancora_mercado.tipo_noite` | `d` (`YYYY-MM-DD`); expected `{tipo, feriado}` (`feriado` is the period's name or null) | `RuleSet::nightType`, `RuleSet::holidayOn` (tested) |
| `pace.curva` | `tipo`, `dias` | `Pricing\PaceCurve::expected` |
| `pace.status_da_noite` | `tipo`, `dias`, `vendidas`, `unidades`; expected `{status, vendido, curva}` | `Pricing\PaceMeter::measure` |
| `pace.passo` | `atual`, `alvo`, `dias`, `fraco` | `Pricing\StepSize::next` |
| `ancora_mercado.quantil` | `valores`, `p` | `Market\Quantile::of` |
| `ancora_mercado.valor_percentil` | `anc` (`p25`..`max`), `pct` | `Market\AnchorLookup::valueAt` |
| `ancora_mercado.percentil_de` | `preco_publico`, `anc` | `Market\AnchorLookup::percentileOf` |

The golden files of the engine core (floors, anchors, one decision per room night, percentages of the payload, occupancy) and every engine class are in [ENGINE.md](ENGINE.md); `tests/Golden/Level1Test.php` checks all of them and fails when a golden file has no test.

### 1.6 Integration of the modules (milestone M1)

What changed when the engine, infrastructure and connectors were put together with the use cases and WordPress. Nothing in `src/Contracts` changed.

| Change | Where |
|---|---|
| `Application\ConnectorSource` (`pms()`, `pricing()`, `readsPricingCalendar()`) is how use cases get connectors; a missing credential is `Application\ConnectionNotConfigured`. `WordPress\ConnectorFactory` implements it; `tests/Fakes/StaticConnectorSource` is its double. | APPLICATION.md 1.2 |
| `RunRepository` records resumable stages: `start(..., ?DateTimeImmutable $runDay = null)`, `advance($runId, $stage, $summary, $state)`, `state($runId)`, `openRun($propertyId, $kind)`, and runs may end `stopped` (emergency switch). Migration 5 restates `runs` with `run_day`, `stage` and `state`. | INFRASTRUCTURE.md 1.3, 1.4 |
| The emergency switch is `Application\EmergencySwitch`: option `ritmo_kill_switch` or constant `RITMO_KILL_SWITCH`. The pricing calendar read is allowed only by the constant `RITMO_PRICING_CALENDAR_READ === true`. | ARCHITECTURE.md 6 |
| One set of test doubles in `tests/Fakes`: `MovableClock` (default instant `2026-11-11T12:00:00Z`, `advance(float)` to the microsecond) replaces the infrastructure's `ManualClock`; `ClockSleeper` (`waits()`, advances the clock by the exact wait) and `ScriptedHttpClient` (`queue()`, a handler, `requests()`, `lines()`) replace the infrastructure's copies. | CONNECTORS.md 6 |
| One generator of signature references: `Tests\Support\ContractsDoc` renders CONTRACTS, ENGINE, INFRASTRUCTURE and APPLICATION (headings name the plugin interfaces a class implements) and `php bin/sync-generated.php` rewrites them all. | this document |
| A `DateRange` is read with `first()` and `last()`; it has no public properties. | Part 2 |

### 1.7 Corrections after the review of M1

What the correction phase changed in shared signatures and behaviour. The only interface of `src/Contracts` that changed is `KeyValueStore`.

| Change | Where |
|---|---|
| `KeyValueStore::getFresh()`: `OptionsStore` drops the option from WordPress's request cache (`options`, `notoptions`, and `alloptions` when autoloaded) before reading it; `ArrayStore` reads its array. `EmergencySwitch::isEngaged()` uses it, so a run sees between two stages the switch engaged by another request. | this document 1.3, APPLICATION.md |
| With the switch engaged, `ShadowRunService::run()` (and the scheduled `resumeOpenRuns()`) closes the property's open run as `stopped` under the run lock; it never resumes from data collected before the emergency. | APPLICATION.md |
| Tonight never goes up, also through rounding: `PaceEngine` limits a step of tonight to the highest whole percentage of the base that does not pass the price (or holds the night), and `Validator` refuses with `raise_today` a floor whose applied amount passes tonight's price. The warning `today_floor_rounds_above_price` is gone. | ENGINE.md 1.4, 1.5 |
| `ValidatedPlan`: `__serialize()`/`__unserialize()` throw `LogicException` (also `PercentPlan`); `Validator::sealed($plan)` is true only for the plans a validator of the process sealed; `ValidatedPlan::$bases` holds the listing base of each room it was validated with, and `PercentRounding::apply()` refuses an unsealed plan or a room with another base. `ArchitectureTest` forbids in `src/` the calls that get around a private constructor. | ENGINE.md 1.5 |
| `Domain\Pricing\RuleInForce` (port of `pace.preco_no_vigente`): the decide stage brings the price read within the floor and ceiling of the date rule in force before the engine, and records `price_within_rule_in_force` (with the price read) at the front of the item's trace. Golden file `pace.preco_no_vigente`. | ENGINE.md 1.4 |
| `SodiumVault(KeyValueStore, Clock, ?string $configuredKey = null, ?string $siteSecret = null)`: without `RITMO_SECRET_KEY` the stored seed is bound to `AUTH_KEY` and `SECURE_AUTH_KEY` of wp-config.php (`forSite()` reads them), so a copy of the database alone decrypts nothing; `install()` moves older entries to the current key (`upgrade()`). `protection()` (`configured_key`, `site_salts`, `database_only`, `unavailable`) is shown in Diagnostics. A `RITMO_SECRET_KEY` shorter than 32 characters no longer throws: `problem()` explains it, `put()`, `reveal()` and `rotate()` throw `SecretUnavailable`, an admin notice and Diagnostics say so, activation and the screens keep working. | INFRASTRUCTURE.md |
| Nothing of a secret is kept in clear: `SecretStatus` is `configured` and `updatedAt` only (the last four characters are gone from the option and from the screen, which shows the date the key was saved). | INFRASTRUCTURE.md |
| `Roles::install(KeyValueStore $settings)` grants by version (`Roles::GRANTS`, setting `roles_version`): a capability the site took away is not given back, a deleted role is not created again. | ARCHITECTURE.md 6 |

**Decided (12 Nov): the calendar read by POST.** The PriceLabs calendar (`POST /listing_prices`) only reads and the engine needs it: it is on by default, for that exact host and path, and a site switches it off with `define('RITMO_PRICING_CALENDAR_READ', false)`. Every other POST, PUT, PATCH and DELETE is still refused by the HTTP guard (ARCHITECTURE.md, section 6).

### 1.8 Integration of milestone M2

What milestone 2 changed in shared signatures and behaviour. No interface of `src/Contracts` changed; the write methods still throw `NotAvailableInThisMilestone` and `WriteToken` still has no factory.

| Change | Where |
|---|---|
| Rule set schema: new sections `relations` (with `price_bands`), `ladder`, `soldout`, `cadence`, `no_effect`, `orders`, `stay` and `plan`, each with neutral defaults, ranges and help (`RuleSetFields::all()` joins the fields of `RelationFields`, `LadderFields`, `ProtectionConfig`, `CadenceConfig`, `NoEffectConfig`, `OrdersConfig`, `StayFields`, `PlanConfig`); typed accessors `RuleSet::relations()`, `ladder()`, `soldOut()`, `cadence()`, `noEffect()`, `orders()`, `stay()`, `plan()`; `RuleReferences` checks the room and property references of those sections. `presets/default.json` and `tests/fixtures/presets/parity.json` (built by `make_parity_preset.py` from the area fixtures `parity-<area>.json`) carry them. | 1.4, the area documents |
| `PlanItem`: new optional parameters at the end (`checkInMask`, `checkOutMask`, `orderId`, `orphan`, `marketEvidence`, `keepReason`, `reasonText`, `readsOccupancy`); `toArray()` gives them. `Plan` takes `deletions` (`PlanDeletion`) and `listingChanges` (`ListingChange`); `without()` drops by position in each list. | ENGINE.md 1.5, VALIDATOR.md |
| `PlanIssue::$subject` (`IssueSubject`: item, deletion, listing, plan) and the floor codes by family (`below_owner_floor`, `below_quality_floor`, ...; `below_hard_floor` is gone). `ValidationResult::rejected(IssueSubject)`. | ENGINE.md 1.12 |
| `Validator::__construct(RuleSet, Clock, ?PlanConfig)` and `validate(Plan, Property, rooms, ?ValidationContext)`: without a context the checks of milestone 2 are neutral. An item whose night the plan deletes first is never `no_effect`, and `PercentRounding::apply()` never drops it. | VALIDATOR.md, ENGINE.md 1.8 |
| `NightContext::$terms` (`Pricing\PaceTerms`, neutral by default) and `PaceDecision::$stepPrice`: what orders, the sales ramp, a market floor, a relation and the chained listing top change in a pace step. | ENGINE.md 1.8 |
| Item reason codes of an assembled plan are upper case (`PACE_STEP`, `LADDER_PRICE`, `SOLD_OUT_PROTECTION`, `RELATION_*`); a single pace decision still turns into `pace_step` through `PlanItem::fromDecision()`. | ENGINE.md 1.8 |
| Shadow run: `DecideStage(RunInputsReader, Clock, string $pmsName)` assembles the whole plan; `ValidateStage()` reads its validation; `ShadowStage(PlanItemRepository, RunPayloadRepository)` also stores deletions, the payload and the slices. New repositories `OrderRepository`, `RunPayloadRepository` (migration 6, table `run_payloads`) and `StepLedgerRepository::ledger()`. `StateCodec::planItem()` / `toPlanItem()` are gone (the stages carry `PlanItem::toArray()`). | APPLICATION.md, INFRASTRUCTURE.md |
| `ConnectorFactory::pricingPmsName()`; the calendar read on by default (above). | APPLICATION.md 1.2 |

## Part 2. Signature reference

Generated from the code. Do not edit between the markers; run `php bin/sync-generated.php`.

<!-- BEGIN GENERATED SIGNATURES (php bin/sync-generated.php) -->

#### `Ritmo\Domain\Support\Clock` (interface)

```php
public function now(): DateTimeImmutable;
public function today(string $timezone): DateTimeImmutable;
```

#### `Ritmo\Domain\Support\DateRange` (final class)

```php
public function __construct(DateTimeInterface $first, DateTimeInterface $last);
public static function nights(DateTimeInterface $first, int $count): self;
public static function stay(DateTimeInterface $arrival, DateTimeInterface $departure): self;
public static function fromStrings(string $first, string $last): self;
public function first(): DateTimeImmutable;
public function last(): DateTimeImmutable;
public function count(): int;
public function contains(DateTimeInterface $night): bool;
public function intersect(self $other): ?self;
public function getIterator(): Generator;
public function keys(): array;
```

#### `Ritmo\Domain\Support\Days` (final class)

```php
public static function date(string $ymd): DateTimeImmutable;
public static function isValid(string $ymd): bool;
public static function key(DateTimeInterface $date): string;
public static function normalize(DateTimeInterface $date): DateTimeImmutable;
public static function localDate(DateTimeInterface $instant, string $timezone): DateTimeImmutable;
public static function add(DateTimeInterface $date, int $days): DateTimeImmutable;
public static function diff(DateTimeInterface $from, DateTimeInterface $to): int;
public static function weekday(DateTimeInterface $date): int;
```

#### `Ritmo\Domain\Support\FixedClock` (final class implements Clock)

```php
public function __construct(DateTimeInterface|string $now);
public function now(): DateTimeImmutable;
public function today(string $timezone): DateTimeImmutable;
public function plusSeconds(int $seconds): self;
```

#### `Ritmo\Domain\Support\Num` (final class)

```php
const EPSILON = 1.0E-9;
public static function roundHalfEven(float $value, int $decimals = 0): float;
public static function roundInt(float $value): int;
public static function ceilInt(float $value): int;
public static function floorInt(float $value): int;
public static function ceilEps(float $value): int;
public static function floorEps(float $value): int;
public static function clamp(float $value, float $min, float $max): float;
public static function floorToPercent(float $amount, float $base): int;
public static function ceilingToPercent(float $amount, float $base): int;
public static function percentToAmount(float $percent, float $base, int $decimals = 0): float;
```

#### `Ritmo\Domain\Support\SystemClock` (final class implements Clock)

```php
public function now(): DateTimeImmutable;
public function today(string $timezone): DateTimeImmutable;
```

#### `Ritmo\Domain\Model\AnchorOrigin` (enum)

```php
case Measured = 'measured';
case Fallback = 'fallback';
```

#### `Ritmo\Domain\Model\BookingRecord` (final class)

```php
public readonly DateTimeImmutable $arrival;
public readonly DateTimeImmutable $departure;
public readonly string $pmsRef;
public readonly ?string $roomCode;
public readonly BookingStatus $status;
public readonly string $channel;
public readonly string $origin;
public readonly ?float $total;
public readonly ?DateTimeImmutable $createdAt;
public readonly ?DateTimeImmutable $modifiedAt;
public readonly ?DateTimeImmutable $cancelledAt;
public readonly string $roomPmsRef;
public readonly int $units;
public function __construct(string $pmsRef, ?string $roomCode, DateTimeImmutable $arrival, DateTimeImmutable $departure, BookingStatus $status, string $channel, string $origin, ?float $total, ?DateTimeImmutable $createdAt, ?DateTimeImmutable $modifiedAt, ?DateTimeImmutable $cancelledAt, string $roomPmsRef = '', int $units = 1);
public function nights(): DateRange;
```

#### `Ritmo\Domain\Model\BookingStatus` (enum)

```php
case Confirmed = 'confirmed';
case NewBooking = 'new';
case Request = 'request';
case Cancelled = 'cancelled';
case Blocked = 'blocked';
case Inquiry = 'inquiry';
public function occupies(): bool;
```

#### `Ritmo\Domain\Model\DateOverride` (final class)

```php
const PRICE_FIXED = 'fixed';
const PRICE_PERCENT = 'percent';
public readonly DateTimeImmutable $date;
public readonly ?float $minPercent;
public readonly ?float $maxPercent;
public readonly ?int $minStay;
public readonly ?string $checkinMask;
public readonly ?string $checkoutMask;
public readonly string $reason;
public readonly ?float $price;
public readonly ?string $priceType;
public readonly ?float $minFixed;
public readonly ?float $maxFixed;
public function __construct(DateTimeImmutable $date, ?float $minPercent = null, ?float $maxPercent = null, ?int $minStay = null, ?string $checkinMask = null, ?string $checkoutMask = null, string $reason = '', ?float $price = null, ?string $priceType = null, ?float $minFixed = null, ?float $maxFixed = null);
public static function isValidMask(string $mask): bool;
public function hasPriceRule(): bool;
```

#### `Ritmo\Domain\Model\Identifier` (final class)

```php
const CODE_BODY = '[A-Za-z0-9][A-Za-z0-9_-]{0,31}';
const CODE_PATTERN = '/^[A-Za-z0-9][A-Za-z0-9_-]{0,31}$/';
const SLUG_PATTERN = '/^[a-z][a-z0-9_]{0,31}$/';
public static function isCode(string $value): bool;
public static function isSlug(string $value): bool;
```

#### `Ritmo\Domain\Model\InventoryNight` (final class)

```php
public readonly DateTimeImmutable $date;
public readonly int $available;
public readonly ?int $minStay;
public readonly ?int $maxStay;
public readonly Restriction $restriction;
public readonly ?float $pmsPrice;
public function __construct(DateTimeImmutable $date, int $available, ?int $minStay = null, ?int $maxStay = null, Restriction $restriction = Restriction::None, ?float $pmsPrice = null);
```

#### `Ritmo\Domain\Model\ListingInfo` (final class)

```php
public readonly string $ref;
public readonly string $name;
public readonly ?float $min;
public readonly ?float $base;
public readonly ?float $max;
public readonly ?string $currency;
public readonly SyncStatus $sync;
public function __construct(string $ref, string $name, ?float $min, ?float $base, ?float $max, ?string $currency, SyncStatus $sync);
```

#### `Ritmo\Domain\Model\MarketAnchor` (final class)

```php
public readonly DateTimeImmutable $date;
public readonly ?DateTimeImmutable $capturedAt;
public readonly float $p25;
public readonly float $p50;
public readonly float $p75;
public readonly float $p90;
public readonly float $max;
public readonly int $n;
public readonly AnchorOrigin $origin;
public function __construct(DateTimeImmutable $date, float $p25, float $p50, float $p75, float $p90, float $max, int $n, ?DateTimeImmutable $capturedAt, AnchorOrigin $origin);
```

#### `Ritmo\Domain\Model\NightType` (enum)

```php
case Weekday = 'weekday';
case Weekend = 'weekend';
case Holiday = 'holiday';
```

#### `Ritmo\Domain\Model\PaceStatus` (enum)

```php
case Ahead = 'ahead';
case OnPace = 'on_pace';
case Behind = 'behind';
case VeryBehind = 'very_behind';
```

#### `Ritmo\Domain\Model\PriceNight` (final class)

```php
public readonly DateTimeImmutable $date;
public readonly float $price;
public readonly ?float $minPrice;
public readonly ?float $maxPrice;
public readonly ?int $minStay;
public readonly int $sold;
public readonly int $total;
public readonly bool $hasDateOverride;
public readonly ?float $userPrice;
public readonly ?float $uncustomizedPrice;
public readonly bool $unbookable;
public function __construct(DateTimeImmutable $date, float $price, ?float $minPrice, ?float $maxPrice, ?int $minStay, int $sold, int $total, bool $hasDateOverride = false, ?float $userPrice = null, ?float $uncustomizedPrice = null, bool $unbookable = false);
public function isSoldOut(): bool;
```

#### `Ritmo\Domain\Model\PricePlan` (enum)

```php
case Sent = 'sent';
case PublicRate = 'public';
case Discounted = 'discounted';
```

#### `Ritmo\Domain\Model\Property` (final class)

```php
public readonly string $id;
public readonly string $name;
public readonly string $timezone;
public readonly string $currency;
public readonly PropertyMode $mode;
public readonly array $occupancyGoals;
public readonly string $tier;
public function __construct(string $id, string $name, string $timezone, string $currency, PropertyMode $mode = PropertyMode::Shadow, array $occupancyGoals = [], string $tier = 'standard');
public static function fromArray(array $data): self;
public function toArray(): array;
```

#### `Ritmo\Domain\Model\PropertyMode` (enum)

```php
case Shadow = 'shadow';
case Approval = 'approval';
case Auto = 'auto';
```

#### `Ritmo\Domain\Model\Restriction` (enum)

```php
case None = 'none';
case Blackout = 'blackout';
case NoCheckin = 'no_checkin';
case NoCheckout = 'no_checkout';
case NoCheckinCheckout = 'no_checkin_checkout';
public function allowsCheckin(): bool;
public function allowsCheckout(): bool;
```

#### `Ritmo\Domain\Model\RoomType` (final class)

```php
public readonly string $propertyId;
public readonly string $code;
public readonly string $name;
public readonly int $units;
public readonly string $pmsRef;
public readonly string $pricingRef;
public readonly float $base;
public readonly float $min;
public readonly float $max;
public readonly float $referenceMin;
public readonly array $tags;
public function __construct(string $propertyId, string $code, string $name, int $units, string $pmsRef, string $pricingRef, float $base, float $min, float $max, float $referenceMin, array $tags = []);
public function hasTag(string $tag): bool;
public function hasAllTags(array $tags): bool;
public static function fromArray(array $data): self;
public function toArray(): array;
```

#### `Ritmo\Domain\Model\SyncStatus` (final class)

```php
public readonly bool $pushEnabled;
public readonly ?DateTimeImmutable $lastPushedAt;
public readonly ?DateTimeImmutable $lastRefreshedAt;
public function __construct(bool $pushEnabled, ?DateTimeImmutable $lastPushedAt = null, ?DateTimeImmutable $lastRefreshedAt = null);
```

#### `Ritmo\Domain\Config\Field` (final class)

```php
const UNIT_PERCENT = 'percent';
const UNIT_DAYS = 'days';
const UNIT_NIGHTS = 'nights';
const UNIT_MONEY = 'money';
const UNIT_RATIO = 'ratio';
public readonly string $key;
public readonly FieldType $type;
public readonly string $label;
public readonly string $help;
public readonly mixed $default;
public readonly bool $required;
public readonly bool $nullable;
public readonly int|float|null $min;
public readonly int|float|null $max;
public readonly bool $exclusiveMin;
public readonly ?array $enum;
public readonly bool $locked;
public readonly ?string $pattern;
public readonly array $requiredKeys;
public readonly bool $unique;
public readonly string $unit;
public function __construct(string $key, FieldType $type, string $label, string $help = '', mixed $default = null, bool $required = false, bool $nullable = false, int|float|null $min = null, int|float|null $max = null, bool $exclusiveMin = false, ?array $enum = null, bool $locked = false, ?string $pattern = null, array $requiredKeys = [], bool $unique = false, string $unit = '');
public function name(): string;
public function parentKey(): ?string;
```

#### `Ritmo\Domain\Config\FieldType` (enum)

```php
case Group = 'group';
case Map = 'map';
case ListOf = 'list';
case Integer = 'int';
case Number = 'float';
case Boolean = 'bool';
case Text = 'string';
case Choice = 'enum';
case Date = 'date';
case Variant = 'variant';
public function isContainer(): bool;
```

#### `Ritmo\Domain\Config\FloorRule` (final class)

```php
public readonly float $amount;
public readonly PricePlan $pricePlan;
public readonly array $nightTypes;
public readonly array $roomTags;
public function __construct(float $amount, PricePlan $pricePlan, array $nightTypes, array $roomTags = []);
public function appliesTo(NightType $nightType, RoomType $room): bool;
```

#### `Ritmo\Domain\Config\Holiday` (final class)

```php
public readonly string $name;
public readonly DateRange $nights;
public readonly NightType $nightType;
public readonly ?int $minStay;
public readonly ?float $floor;
public function __construct(string $name, DateRange $nights, NightType $nightType, ?int $minStay, ?float $floor);
public function covers(DateTimeInterface $night): bool;
```

#### `Ritmo\Domain\Config\InvalidConfig` (final class extends InvalidArgumentException)

```php
public function __construct(string $what, array $errors);
public function errors(): array;
```

#### `Ritmo\Domain\Config\Preset` (final class)

```php
const FORMAT = 'ritmo-revenue-preset';
const FORMAT_VERSION = 1;
public readonly string $name;
public readonly string $description;
public readonly array $properties;
public readonly array $roomTypes;
public readonly RuleSet $rules;
public function __construct(string $name, string $description, array $properties, array $roomTypes, RuleSet $rules);
public function property(string $id): ?Property;
public function roomType(string $code): ?RoomType;
public function roomTypesOf(string $propertyId): array;
public function toArray(): array;
```

#### `Ritmo\Domain\Config\PresetLoader` (final class)

```php
public function fromFile(string $path): Preset;
public function fromJson(string $json): Preset;
public function fromArray(array $data): Preset;
```

#### `Ritmo\Domain\Config\RuleReferences` (final class)

```php
const EVERY_ROOM = '*';
public static function unknown(array $rules, array $roomCodes, array $propertyIds): array;
```

#### `Ritmo\Domain\Config\RuleSet` (final class)

```php
public static function fromArray(array $data): self;
public static function defaults(): self;
public function toArray(): array;
public function hash(): string;
public function get(string $path): mixed;
public function occupancyGoals(): array;
public function occupancyGoalsFor(Property $property): array;
public function weekendDays(): array;
public function holidays(): array;
public function holidayOn(DateTimeInterface $night): ?Holiday;
public function nightType(DateTimeInterface $night): NightType;
public function paceCurve(NightType $type): array;
public function paceBand(): float;
public function paceVeryBehind(): float;
public function zeroSoldDays(NightType $type): int;
public function groupPaceMaxUnits(): int;
public function groupPaceRoomTags(): array;
public function targetPercentiles(string $tier): array;
public function targetPercentile(string $tier, PaceStatus $status, int $daysOut): float;
public function aheadNearDays(): int;
public function stepStrength(): float;
public function maxStepPercent(int $daysOut): float;
public function minSampleSize(): int;
public function maxSampleAgeDays(): int;
public function tolerance(): float;
public function protectionPercentile(): float;
public function referenceMinFraction(): float;
public function ownerFloors(): array;
public function qualityFloor(): FloorRule;
public function holidayFloor(): FloorRule;
public function priceFactor(string $propertyId, PricePlan $plan, NightType $type, ?string $roomCode = null): float;
public function weeklyListingChangeMax(): float;
public function neverRaiseToday(): bool;
public function salesRampTop(): ?float;
public function relations(): RelationsConfig;
public function ladder(): LadderConfig;
public function soldOut(): ProtectionConfig;
public function cadence(): CadenceConfig;
public function noEffect(): NoEffectConfig;
public function orders(): OrdersConfig;
public function stay(): StayConfig;
public function plan(): PlanConfig;
```

#### `Ritmo\Domain\Config\RuleSetFields` (final class)

```php
const GOAL_WINDOWS = [7, 15, 30, 45, 60];
const CURVE_DAYS = [60, 30, 14, 7, 3, 1, 0];
const TARGET_STATUSES = ['ahead_near', 'ahead', 'on_pace', 'behind', 'very_behind'];
const DEFAULT_TIER = 'standard';
const ANY_PROPERTY = '*';
public static function all(): array;
public static function nested(string $prefix, array $fields): array;
```

#### `Ritmo\Domain\Config\RuleSetValidator` (final class)

```php
public function validate(array $data): array;
public function validateSection(string $root, mixed $value): array;
```

#### `Ritmo\Domain\Config\Schema` (final class)

```php
const VERSION = 1;
const WILDCARD = '*';
public function __construct(array $fields);
public static function ruleSet(): self;
public function fields(): array;
public function has(string $key): bool;
public function field(string $key): Field;
public function children(string $key): array;
public function keyForPath(string $path): ?string;
public function defaults(): array;
public function withDefaults(array $data): array;
```

#### `Ritmo\Domain\Config\ValidationError` (final class)

```php
const REQUIRED = 'required';
const UNKNOWN = 'unknown';
const TYPE = 'type';
const RANGE = 'range';
const CHOICE = 'choice';
const FORMAT = 'format';
const UNIQUE = 'unique';
const LOCKED = 'locked';
const ORDER = 'order';
const REFERENCE = 'reference';
public readonly string $path;
public readonly string $code;
public readonly string $message;
public function __construct(string $path, string $code, string $message);
public function __toString(): string;
```

#### `Ritmo\Contracts\AiProvider` (interface)

```php
public function id(): string;
public function complete(string $system, array $messages, int $maxTokens): string;
```

#### `Ritmo\Contracts\ConnectionStatus` (final class)

```php
public readonly bool $ok;
public readonly string $message;
public readonly array $details;
public function __construct(bool $ok, string $message, array $details = []);
```

#### `Ritmo\Contracts\Exception\AuthFailed` (final class extends ConnectorException)

```php
public function __construct(string $message, string $provider = '', ?int $httpStatus = 401, ?Throwable $previous = null);
```

#### `Ritmo\Contracts\Exception\ConnectorException` (class extends RuntimeException)

```php
public function __construct(string $message, string $provider = '', ?int $httpStatus = null, bool $retryable = false, ?Throwable $previous = null);
public function provider(): string;
public function httpStatus(): ?int;
public function isRetryable(): bool;
```

#### `Ritmo\Contracts\Exception\NotAvailableInThisMilestone` (final class extends LogicException)

```php
public static function forOperation(string $operation): self;
```

#### `Ritmo\Contracts\Exception\RateLimited` (final class extends ConnectorException)

```php
public function __construct(string $message, string $provider = '', ?int $retryAfterSeconds = null, ?int $httpStatus = 429, ?Throwable $previous = null);
public function retryAfterSeconds(): ?int;
```

#### `Ritmo\Contracts\Exception\WriteBlocked` (final class extends RuntimeException)

```php
public static function forRequest(string $method, string $target, string $why): self;
```

#### `Ritmo\Contracts\HttpClient` (interface)

```php
public function request(string $method, string $url, array $headers = [], ?string $body = null, int $timeout = 30): HttpResponse;
```

#### `Ritmo\Contracts\HttpResponse` (final class)

```php
public function __construct(int $status, array $headers = [], string $body = '');
public function status(): int;
public function isSuccess(): bool;
public function headers(): array;
public function header(string $name): ?string;
public function body(): string;
public function json(): array;
```

#### `Ritmo\Contracts\KeyValueStore` (interface)

```php
public function get(string $key, mixed $default = null): mixed;
public function getFresh(string $key, mixed $default = null): mixed;
public function has(string $key): bool;
public function set(string $key, mixed $value): void;
public function delete(string $key): void;
```

#### `Ritmo\Contracts\LicenceProvider` (interface)

```php
public function id(): string;
public function check(string $licenceKey, string $siteUrl): LicenceState;
```

#### `Ritmo\Contracts\LicenceState` (final class)

```php
const VALID = 'valid';
const EXPIRED = 'expired';
const INVALID = 'invalid';
const UNREACHABLE = 'unreachable';
public readonly string $status;
public readonly ?DateTimeImmutable $expiresAt;
public readonly DateTimeImmutable $checkedAt;
public readonly string $message;
public function __construct(string $status, ?DateTimeImmutable $expiresAt, DateTimeImmutable $checkedAt, string $message = '');
public function isValid(): bool;
```

#### `Ritmo\Contracts\Logger` (interface)

```php
const DEBUG = 'debug';
const INFO = 'info';
const WARNING = 'warning';
const ERROR = 'error';
public function log(string $level, string $message, array $context = []): void;
public function debug(string $message, array $context = []): void;
public function info(string $message, array $context = []): void;
public function warning(string $message, array $context = []): void;
public function error(string $message, array $context = []): void;
```

#### `Ritmo\Contracts\MarketDataProvider` (interface)

```php
public function id(): string;
public function readAnchors(string $propertyId, DateTimeImmutable $from, DateTimeImmutable $to): array;
```

#### `Ritmo\Contracts\PmsConnector` (interface)

```php
public function id(): string;
public function testConnection(): ConnectionStatus;
public function readBookingsByStay(DateTimeImmutable $from, DateTimeImmutable $to): array;
public function readBookingsModifiedSince(DateTimeImmutable $instant): array;
public function readInventory(array $roomRefs, DateTimeImmutable $from, DateTimeImmutable $to, bool $withPrices = false): array;
public function writeCalendar(WriteToken $token, array $changes): void;
```

#### `Ritmo\Contracts\PricingConnector` (interface)

```php
public function id(): string;
public function testConnection(): ConnectionStatus;
public function listListings(): array;
public function readPrices(string $listingRef, DateTimeImmutable $from, DateTimeImmutable $to): array;
public function readOverrides(string $listingRef, DateTimeImmutable $from, DateTimeImmutable $to): array;
public function requestRecalculation(WriteToken $token, string $listingRef): void;
public function writeOverrides(WriteToken $token, string $listingRef, array $overrides): void;
public function deleteOverrides(WriteToken $token, string $listingRef, array $dates): void;
public function updateListing(WriteToken $token, string $listingRef, array $fields): void;
```

#### `Ritmo\Contracts\QuotaLedger` (interface)

```php
public function record(string $provider, string $action, string $scope, bool $succeeded, DateTimeImmutable $at): void;
public function countSince(string $provider, string $action, string $scope, DateTimeImmutable $since): int;
```

#### `Ritmo\Contracts\Support\PmsWritesUnavailable` (trait)

```php
public function writeCalendar(WriteToken $token, array $changes): void;
```

#### `Ritmo\Contracts\Support\PricingWritesUnavailable` (trait)

```php
public function requestRecalculation(WriteToken $token, string $listingRef): void;
public function writeOverrides(WriteToken $token, string $listingRef, array $overrides): void;
public function deleteOverrides(WriteToken $token, string $listingRef, array $dates): void;
public function updateListing(WriteToken $token, string $listingRef, array $fields): void;
```

#### `Ritmo\Contracts\WriteToken` (final class)

```php
// no public members
```

<!-- END GENERATED SIGNATURES -->
