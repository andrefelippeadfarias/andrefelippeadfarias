# Ritmo Revenue: the rule engine

What `src/Domain/Calendar`, `Market`, `Pricing`, `Plan` and `Report` do and how the application calls them. Everything here is pure PHP over the shared types of [CONTRACTS.md](CONTRACTS.md) and follows the conventions of [ARCHITECTURE.md](ARCHITECTURE.md). Part 1 explains; Part 2 is the exact signature of every public member, generated from the code by `php bin/sync-generated.php` (a test fails when it is stale).

Milestone 1 holds the core of the pace engine. Room relations, stay rules, the sales ladder, owner orders and the protection of sold-out nights come in the next milestone (section 1.8).

## Part 1. Guide

### 1.1 One shadow run, end to end

```php
$rules   = /* RuleSet in force */;
$clock   = /* Clock */;
$lookup  = new AnchorLookup($rules, $anchors);            // $anchors: 'YYYY-MM-DD' => MarketAnchor of the property
$engine  = new PaceEngine($rules, $clock);
$basis   = new PaceBasis($rules);

$decisions = [];
foreach ($rooms as $room) {
    foreach ($nightsOfTheRoom as $night) {                // from today on
        // $sales: room code => [sold, units on sale] of every room of the property on that night
        $group = $basis->groupFor($room, $rooms, $sales[$night->key]);   // null: the room is read on its own
        $decisions[] = $engine->decide(new NightContext(
            $property, $room, $night->date,
            $night->currentPrice,                         // sent plan
            $night->sold, $night->unitsOnSale,
            $lookup->forNight($night->date),              // null when there is no anchor
            $night->floorInForce, $night->ceilingInForce, // null when unknown
            $lastStepOfTheLedger,                         // ?LedgerStep
            $group[0] ?? null, $group[1] ?? null,         // pace basis
        ));
    }
}

$plan   = Plan::fromDecisions($property, $clock, $decisions, $before);   // $before: PlanItem::keyOf() => NightState
$result = (new Validator($rules, $clock))->validate($plan, $property, $rooms);
if ($result->isValid()) {
    $percent = (new PercentRounding())->apply($result->plan, $rooms);    // what would be written, in % over the base
}
```

Every decision is kept, not only the steps: `PaceDecision::toArray()` is the row of the nights grid, with the reason of each night. Nothing in this folder writes anywhere.

### 1.2 Calendar

`HolidayCalendar` answers for a rule set: `nightType($night)`, `holidayOn($night)` (the first configured period that covers the night), `isInPeriod()`, `classify(DateRange)` (`'YYYY-MM-DD' => NightType`) and `periodsIn(DateRange)`. A night inside a period takes the night type the period declares, which may be `weekday`, `weekend` or `holiday`; outside every period it follows `calendar.weekend_days`.

### 1.3 Market

| Class | Use |
|---|---|
| `Quantile` | `of($values, $p)` (linear between ranks; `NAN` for an empty list) and `median()`. |
| `PriceSample` | One public price of a competitor for one night: `night`, `publicPrice`, `capturedAt`, optional `source`. |
| `AnchorBuilder` | `fromSamples($night, $samples)` / `build($samples)`: p25, p50, p75, p90 and max of the samples of each night (whole amounts by default; only the newest sample of each named source counts; the anchor is dated by its newest sample). `merge($existing, $fresh, $today)` applies `replaces()`. `isWeak($anchor, $today)`: `n < pace.min_n` or older than `pace.max_age_days`. `monthlyFallbacks($anchors)`: per night type and month, the median of the measured anchors. |
| `AnchorLookup` | `forNight($night)`: the night's own anchor; else the fallback of its night type in its month; else in the closest month; else null. A fallback has `n = 0` and origin `fallback`, so it is weak. `AnchorLookup::valueAt($anchor, $percentile)` and `percentileOf($anchor, $publicPrice)` convert between percentile and public price. |
| `PriceFactor` | `factor($room, $plan, $type)`, `toSent()`, `fromSent()`, `convert()`: plan price = sent price x factor. |

`replaces($new, $old, $today)`: an estimate (origin `fallback`) does not replace a measured anchor that is still fresh; a fresh measured anchor always replaces an estimate; otherwise the newer capture wins and, on the same date, the larger or equal sample.

### 1.4 Pricing

`PaceEngine::decide(NightContext): PaceDecision` applies, in this order:

1. **Outside the engine:** a night before today (`past_night`), a sold-out night (`sold_out_pending_m2`), a night without anchor (`no_market_anchor`). The decision is `Skipped`.
2. **Pace status** (`PaceMeter`): sold share against the curve of the night type (`PaceCurve`), with the bands of the rule set and the "nothing sold close to the night" rule. The share is the room's own unless the context gives a group (`paceSold`, `paceUnits`): with `pace.group_pace` on, `PaceBasis::groupFor($room, $rooms, $sales)` gives the group of a room type with few units (the room types of its property with the configured tags; an overbooked room counts as full), or null.
3. **Target** (`TargetPercentile`, `AnchorLookup::valueAt`, `PriceFactor`): the market percentile of the status for the property's tier, as a public price, divided by the public factor.
4. **A night ahead of its curve never goes down:** its target is at least the current price.
5. **One step** (`StepSize`): `pace.k` times the distance, limited by `pace.max_step`, half of the limit with a weak anchor.
6. **Hard floor** (`Floors::hardFloor`): the step stops there (`heldAtFloor`).
7. **Listing maximum:** the step stops at `Floors::highestFloor()`.
8. **Tonight never goes up**, not even through the rounding of the floor: a step down whose floor the pricing system would apply (a whole percentage of the base, rounded up) above the current price goes down to the highest whole percentage that does not pass it; if that is under the hard floor, tonight keeps its price. This is a deliberate difference from the reference, which does not look at the rounding on the night of today.
9. **Tolerance:** a change within `pace.tolerance` is not a step (`Hold`, `within_tolerance`). Otherwise the price is rounded to a whole currency unit, up when rising and down when falling.
10. **Cadence:** a step in the same direction as the last one of the ledger waits until that one reached the sales channels and 24 hours passed (`Hold`, `cadence_previous_step_not_pushed` or `cadence_step_within_24h`).
11. **The item** (`Step`, `pace_step`): `minPrice` is the new floor. Going up, `maxPrice` is the target (never under the floor), so the pricing algorithm cannot jump past the step. Going down, `maxPrice` follows the floor only when the current price is held above it by the algorithm or by an older ceiling. A last pass keeps the floor, as a whole percentage over the base, under the listing maximum.

**Types.** `NightContext` is the input (see 1.1; `NightContext::fromPriceNight()` builds it from a `PriceNight`). `LedgerStep` is the last step of the ledger for the room night: `direction`, `recordedAt` (instant), `reachedChannel`. `PaceDecision` carries `kind` (`DecisionKind`: `step`, `hold`, `skipped`), `reasonCode`, `direction` (`StepDirection`: `up`, `down`), `reading` (`PaceReading`: `status`, `soldPercent`, `expectedPercent`, `gap()`), `floor` (`HardFloor`: `amount`, `label`, `candidates`), the target and the trace.

**The hard floor** is the highest of: the listing minimum (`listing_min`), the safety limit `floors.fraction_of_reference_min` x reference minimum (`reference_min_fraction`), the owner floors of the night type (`owner_floor`), the quality floor (`quality_floor`) and, on a night of a configured period, the period's own floor or the general holiday floor, whichever is higher (`holiday_floor`). Each amount is divided by the factor of its price plan for the room and night type. On a tie the label is, in this order of preference: owner, holiday, quality, listing minimum, reference fraction. `Floors::safetyLimit($room)` is the fraction of the reference minimum alone.

**Outcomes** (`PaceDecision::$kind` and `$reasonCode`):

| Kind | Reason code | Meaning |
|---|---|---|
| `step` | `pace_step` | A floor (and maybe a ceiling) is proposed: `minPrice`, `maxPrice`, `direction`. |
| `hold` | `within_tolerance` | The price is where it should be. |
| `hold` | `cadence_previous_step_not_pushed` | The previous step in that direction has not reached the channels. |
| `hold` | `cadence_step_within_24h` | There was a step in that direction in the last 24 hours. |
| `skipped` | `past_night` | The night is before the property's today. |
| `skipped` | `sold_out_pending_m2` | Every unit is sold; protection of sold-out nights comes later. |
| `skipped` | `no_market_anchor` | No anchor for the night nor for its night type. |

**Rule trace** (`PaceDecision::$trace`, a list of `{code, params}`; `traceParams($code)` reads one). Codes are the constants of `RuleCode`; parameters are scalars or null, with snake_case names. The admin turns them into text.

| Code | Parameters |
|---|---|
| `night` | `night_type`, `days_out`, `period` (name of the configured period or null) |
| `pace_status` | `status`, `sold`, `units`, `basis` (`room` or `group`), `sold_percent`, `expected_percent` |
| `market_target` | `tier`, `ahead_near`, `percentile`, `public`, `factor`, `sent`, `anchor_origin`, `anchor_n`, `anchor_captured_at` |
| `weak_anchor` | `n`, `age_days` |
| `ahead_never_lowers` | `target`, `current` |
| `step` | `from`, `to`, `strength`, `max_percent` |
| `held_at_floor` | `floor`, `label`, `public`, `percentile` |
| `capped_at_listing_max` | `highest_floor`, `listing_max` |
| `today_never_rises` | `wanted`, `current`, `applied_floor` (the wanted floor as the pricing system applies it, a whole percentage of the base rounded up), `to` (the price kept: the current one, or the highest whole percentage of the base that does not pass it) |
| `within_tolerance` | `tolerance`, `from`, `to` |
| `cadence_previous_step_not_pushed`, `cadence_step_within_24h` | `direction`, `last_step_at`, `wanted` |
| `ceiling_at_target` | `ceiling`, `target` |
| `ceiling_follows_floor` | `ceiling`, `floor_in_force`, `ceiling_in_force` |
| `pace_step` | `direction`, `from`, `min_price`, `max_price` |
| `past_night`, `sold_out_pending_m2`, `no_market_anchor` | `sold`, `units` |

`PaceDecision::$proposedPrice` is the price the engine arrived at in whole units (also for a hold); `$minPrice` and `$maxPrice` exist only for a step. `$direction` of a step is the direction of what is written, which is what the step ledger records.

### 1.5 Plan

- `PlanItem`: `roomCode`, `date`, `minPrice`, `maxPrice`, `minStay` (each nullable, at least one set), `reasonCode`, `reasonParams`, `before` (`NightState`: `price`, `minPrice`, `maxPrice`, `minStay`, `minPercent`, `maxPercent` in force; null = not known). `PlanItem::fromDecision()` builds the item of a step. The constructor checks the shape only; the rules are the validator's.
- `Plan`: `propertyId`, `today`, `createdAt`, `items`. `Plan::fromDecisions($property, $clock, $decisions, $before)` keeps the steps. `without($indexes)` drops items by position.
- `Validator::validate($plan, $property, $rooms): ValidationResult` gives `errors`, `warnings` (lists of `PlanIssue`: `severity`, `code`, `message`, `itemIndex`, `roomCode`, `date`, `params`) and, only when there is no error, `plan` (`ValidatedPlan`). `rejectedItems()` lists the positions of the items with errors, so the application can show them or validate again without them.
- `ValidatedPlan` has a private constructor and no factory: only the validator creates it, and `Validator::sealed($plan)` is true only for the plans a validator of this process sealed (a WeakMap), so an object made through Reflection is not taken as validated. It cannot be serialised nor unserialised (`LogicException`; `PercentPlan` likewise). It keeps `validatedAt`, `today`, the property's `timezone`, `rulesHash`, the warnings and `bases` (room code => listing base the items were checked with). `isCurrent($clock)` is false once the property's day changed: such a plan is redone, never written. `ArchitectureTest` forbids in `src/` the calls that get around a private constructor (closure rebinding, `newInstanceWithoutConstructor`, `setAccessible`, `unserialize`), except the validator's own `seal()`.
- `PercentRounding::convert($item, $room): PercentItem` gives `minPercent` (rounded up) and `maxPercent` (rounded down, never under the floor) over the listing base, with `minAmount()` / `maxAmount()` as the pricing system will apply them. `apply($validatedPlan, $rooms): PercentPlan` refuses (`InvalidArgumentException`) a plan the validator did not seal and a room whose base is not the one in `bases`, converts every item and moves to `dropped` those whose percentages (and stay) are the ones already in force (`NightState::$minPercent`, `$maxPercent`).

Validator errors (floors are compared as applied: a whole percentage over the base, rounded up):

| Code | Rejects |
|---|---|
| `stale_plan` | The plan was computed for a day that is not the property's today. |
| `unknown_room` | A room that is not a room type of the property. |
| `duplicate_item` | A second item for the same room night. |
| `missing_reason` | An item without `reasonCode`. |
| `past_date` | A night before today. |
| `raise_today` | A floor for tonight above tonight's price, also when only its rounding up to a whole percentage of the base puts it above (params `floor`, `applied_floor`, `current_price`). |
| `today_without_current_price` | A floor for tonight without `before->price`. |
| `below_safety_limit` | A floor (or a ceiling without floor) under the safety limit. |
| `below_listing_min`, `below_hard_floor` | A floor (or a ceiling without floor) more than one currency unit under the hard floor of the night; the code says whether that floor is the listing minimum. |
| `above_listing_max` | A floor or a ceiling over the listing maximum. |
| `ceiling_below_floor` | A ceiling under the floor of the item or, without one, under the floor in force. |
| `no_effect` | Everything the item sets is already in force (amounts within one unit, same stay). |

Warnings: `rise_above_max_step` (the floor raises the price by more than one step of the engine), `no_before` (the item has no `before`, so its effect cannot be checked).

A hard floor above the listing maximum is a configuration conflict: the engine goes to the maximum and the validator reports `below_hard_floor`, for a person to solve.

### 1.6 Report

| Class | Use |
|---|---|
| `OccupancyWindows` | `measure($property, $rooms, $inventory, $today): OccupancyReport`. `$inventory` is `room code => list<InventoryNight>`. Per room and for the property, in each window of `RuleSet::occupancyGoalsFor($property)`: `WindowOccupancy` (`days`, `sold`, `capacity`, `percent` with one decimal or null, `goal`, `meetsGoal()`). Sold = units - available, between 0 and the units; nights not reported are left out. `OccupancyReport::windowsUnderGoal()`. |
| `OverbookingDetector` | `detect($inventory): list<OverbookedNight>`: the nights with `available < 0` (`unitsOver()`), by night and room. |
| `BookedUnits` | `byRoomNight($bookings, $range)`: `room code => 'YYYY-MM-DD' => units` from the bookings that occupy (confirmed and new). |
| `PmsPricingMismatch` | `find($bookings, $pricing, $range): list<SoldMismatch>`: the nights of the pricing system (`room code => list<PriceNight>`) whose units sold differ from the PMS bookings; `difference()` is pricing minus PMS. |

### 1.7 Parity with the reference engine

`tests/Golden/Level1Test.php` runs every file of `tests/fixtures/golden/level1/` and fails when a file has no test. Unrounded floats must match within 1e-9, amounts within 0.01, percentages, counts and classifications exactly.

| Golden file | PHP |
|---|---|
| `pace.curva`, `pace.status_da_noite`, `pace.percentil_alvo`, `pace.pmax`, `pace.passo` | `PaceCurve`, `PaceMeter`, `TargetPercentile`, `StepSize` |
| `pace.pisos_enviados`, `pace.limite_seguranca`, `validar_plano.piso_absoluto`, `pace.teto_anuncio` | `Floors::hardFloor`, `safetyLimit`, `highestFloor` |
| `pace.fator_publico` | `PriceFactor::factor` |
| `ancora_mercado.quantil`, `valor_percentil`, `percentil_de` | `Quantile::of`, `AnchorLookup::valueAt`, `percentileOf` |
| `ancora_mercado.substitui`, `construir`, `ancora_da_noite` | `AnchorBuilder::replaces`, `monthlyFallbacks`, `AnchorLookup::forNight` |
| `ancora_mercado.tipo_noite` | `HolidayCalendar` |
| `pace.plano.core` | `PaceBasis::groupFor` and `PaceEngine::decide`: 1122 room nights of `pace.plano` over synthetic calendars |
| `validar_plano.montar_payload` | `PercentRounding::convert` |
| `ocupacao.calcular`, `beds24.comparar` | `OccupancyWindows`, `PmsPricingMismatch` |
| `validar_plano.piso_pct`, `teto_pct`, `em_reais`, `pace.efetivo`, `builtins.round` | `Num` |

What the goldens of the core leave out, because the parity preset leaves it out too: dated owner exceptions to the safety limit, per-room holiday floors, the sales ladder, owner orders, the owner's dated plan and the pass of relations between rooms (the last two are switched off in memory by the dump tool; the engine's files are never changed). The reference caps the highest floor of rooms tied by a relation with the chain of that relation; those rooms are left out of `pace.teto_anuncio` and never get a price above their maximum in `pace.plano.core`.

Where the plugin generalises the reference:

- The quality floor is one more candidate of the hard floor. With the reference's numbers it never wins, so the results are the same.
- `replaces()` reads the reference's "measured median" flag as the anchor origin (measured or estimate).
- The validator compares floors as applied, not as asked: with whole-unit steps, an asked floor is up to one unit under the hard floor it was held at.
- The no-effect check covers any item (floor, ceiling or stay), where the reference only looks at locked prices.

### 1.8 Not in this milestone

Room relations, stay and arrival rules, the sales ladder, owner orders and dated exceptions, protection of sold-out nights, cadence released by a new sale, payload slices, the step ledger itself. `OccupancyWindows` counts a blocked night as sold, because the PMS inventory does not tell a block from a sale; `PmsPricingMismatch` is what shows blocks without a booking.

## Part 2. Signature reference

Generated from the code. Do not edit between the markers; run `php bin/sync-generated.php`.

<!-- BEGIN GENERATED SIGNATURES (php bin/sync-generated.php) -->

#### `Ritmo\Domain\Calendar\HolidayCalendar` (final class)

```php
public function __construct(RuleSet $rules);
public function holidayOn(DateTimeInterface $night): ?Holiday;
public function isInPeriod(DateTimeInterface $night): bool;
public function nightType(DateTimeInterface $night): NightType;
public function classify(DateRange $range): array;
public function periodsIn(DateRange $range): array;
```

#### `Ritmo\Domain\Market\AnchorBuilder` (final class)

```php
const MONTH_ANCHOR_DAY = 15;
public function __construct(RuleSet $rules, int $decimals = 0);
public function fromSamples(DateTimeInterface $night, array $samples): ?MarketAnchor;
public function build(array $samples): array;
public function merge(array $existing, array $fresh, DateTimeInterface $today): array;
public function replaces(MarketAnchor $new, MarketAnchor $old, DateTimeInterface $today): bool;
public function ageInDays(MarketAnchor $anchor, DateTimeInterface $today): int;
public function isWeak(MarketAnchor $anchor, DateTimeInterface $today): bool;
public function monthlyFallbacks(array $anchors): array;
```

#### `Ritmo\Domain\Market\AnchorLookup` (final class)

```php
public function __construct(RuleSet $rules, array $anchors, ?AnchorBuilder $builder = null);
public function forNight(DateTimeInterface $night): ?MarketAnchor;
public static function valueAt(MarketAnchor $anchor, float $percentile): float;
public static function percentileOf(MarketAnchor $anchor, float $publicPrice): float;
```

#### `Ritmo\Domain\Market\PriceFactor` (final class)

```php
public function __construct(RuleSet $rules);
public function factor(RoomType $room, PricePlan $plan, NightType $type): float;
public function toSent(float $amount, PricePlan $from, RoomType $room, NightType $type): float;
public function fromSent(float $amount, PricePlan $to, RoomType $room, NightType $type): float;
public function convert(float $amount, PricePlan $from, PricePlan $to, RoomType $room, NightType $type): float;
```

#### `Ritmo\Domain\Market\PriceSample` (final class)

```php
public readonly DateTimeImmutable $night;
public readonly DateTimeImmutable $capturedAt;
public readonly float $publicPrice;
public readonly string $source;
public function __construct(DateTimeImmutable $night, float $publicPrice, DateTimeImmutable $capturedAt, string $source = '');
```

#### `Ritmo\Domain\Market\Quantile` (final class)

```php
public static function of(array $values, float $p): float;
public static function median(array $values): float;
```

#### `Ritmo\Domain\Pricing\DecisionKind` (enum)

```php
case Step = 'step';
case Hold = 'hold';
case Skipped = 'skipped';
```

#### `Ritmo\Domain\Pricing\Floors` (final class)

```php
const LISTING_MIN = 'listing_min';
const REFERENCE_MIN_FRACTION = 'reference_min_fraction';
const QUALITY = 'quality_floor';
const HOLIDAY = 'holiday_floor';
const OWNER = 'owner_floor';
public function __construct(RuleSet $rules, ?PriceFactor $factors = null);
public function safetyLimit(RoomType $room): float;
public function highestFloor(RoomType $room): float;
public function hardFloor(RoomType $room, DateTimeInterface $night): HardFloor;
```

#### `Ritmo\Domain\Pricing\HardFloor` (final class)

```php
public readonly float $amount;
public readonly string $label;
public readonly array $candidates;
public function __construct(float $amount, string $label, array $candidates);
```

#### `Ritmo\Domain\Pricing\LedgerStep` (final class)

```php
public readonly DateTimeImmutable $recordedAt;
public readonly StepDirection $direction;
public readonly bool $reachedChannel;
public function __construct(StepDirection $direction, DateTimeImmutable $recordedAt, bool $reachedChannel);
```

#### `Ritmo\Domain\Pricing\NightContext` (final class)

```php
public readonly DateTimeImmutable $night;
public readonly int $paceSold;
public readonly int $paceUnits;
public readonly Property $property;
public readonly RoomType $room;
public readonly float $currentPrice;
public readonly int $sold;
public readonly int $units;
public readonly ?MarketAnchor $anchor;
public readonly ?float $floorInForce;
public readonly ?float $ceilingInForce;
public readonly ?LedgerStep $lastStep;
public function __construct(Property $property, RoomType $room, DateTimeImmutable $night, float $currentPrice, int $sold, int $units, ?MarketAnchor $anchor, ?float $floorInForce = null, ?float $ceilingInForce = null, ?LedgerStep $lastStep = null, ?int $paceSold = null, ?int $paceUnits = null);
public static function fromPriceNight(Property $property, RoomType $room, PriceNight $price, ?MarketAnchor $anchor, ?LedgerStep $lastStep = null): self;
public function isSoldOut(): bool;
public function usesGroupPace(): bool;
```

#### `Ritmo\Domain\Pricing\PaceBasis` (final class)

```php
public function __construct(RuleSet $rules);
public function groupFor(RoomType $room, array $rooms, array $sales): ?array;
```

#### `Ritmo\Domain\Pricing\PaceCurve` (final class)

```php
public function __construct(RuleSet $rules);
public function expected(NightType $type, int $daysOut): float;
```

#### `Ritmo\Domain\Pricing\PaceDecision` (final class)

```php
public readonly string $roomCode;
public readonly DateTimeImmutable $night;
public readonly int $daysOut;
public readonly NightType $nightType;
public readonly DecisionKind $kind;
public readonly string $reasonCode;
public readonly float $currentPrice;
public readonly array $trace;
public readonly ?MarketAnchor $anchor;
public readonly ?PaceReading $reading;
public readonly ?float $targetPercentile;
public readonly ?float $targetPublic;
public readonly ?float $targetSent;
public readonly ?float $publicFactor;
public readonly ?float $currentPercentile;
public readonly ?float $proposedPrice;
public readonly ?StepDirection $direction;
public readonly ?float $minPrice;
public readonly ?float $maxPrice;
public readonly ?HardFloor $floor;
public readonly bool $heldAtFloor;
public readonly bool $weakAnchor;
public function __construct(string $roomCode, DateTimeImmutable $night, int $daysOut, NightType $nightType, DecisionKind $kind, string $reasonCode, float $currentPrice, array $trace, ?MarketAnchor $anchor = null, ?PaceReading $reading = null, ?float $targetPercentile = null, ?float $targetPublic = null, ?float $targetSent = null, ?float $publicFactor = null, ?float $currentPercentile = null, ?float $proposedPrice = null, ?StepDirection $direction = null, ?float $minPrice = null, ?float $maxPrice = null, ?HardFloor $floor = null, bool $heldAtFloor = false, bool $weakAnchor = false);
public function isStep(): bool;
public function applied(string $ruleCode): bool;
public function traceParams(string $ruleCode): ?array;
public function toArray(): array;
```

#### `Ritmo\Domain\Pricing\PaceEngine` (final class)

```php
const STEP_INTERVAL_SECONDS = 86400;
public function __construct(RuleSet $rules, Clock $clock);
public function decide(NightContext $context): PaceDecision;
```

#### `Ritmo\Domain\Pricing\PaceMeter` (final class)

```php
public function __construct(RuleSet $rules, ?PaceCurve $curve = null);
public function measure(NightType $type, int $daysOut, int $sold, int $units): PaceReading;
```

#### `Ritmo\Domain\Pricing\PaceReading` (final class)

```php
public readonly PaceStatus $status;
public readonly float $soldPercent;
public readonly float $expectedPercent;
public function __construct(PaceStatus $status, float $soldPercent, float $expectedPercent);
public function gap(): float;
```

#### `Ritmo\Domain\Pricing\RuleCode` (final class)

```php
const NIGHT = 'night';
const PAST_NIGHT = 'past_night';
const SOLD_OUT_PENDING_M2 = 'sold_out_pending_m2';
const NO_MARKET_ANCHOR = 'no_market_anchor';
const PACE_STATUS = 'pace_status';
const MARKET_TARGET = 'market_target';
const WEAK_ANCHOR = 'weak_anchor';
const AHEAD_NEVER_LOWERS = 'ahead_never_lowers';
const STEP = 'step';
const HELD_AT_FLOOR = 'held_at_floor';
const CAPPED_AT_LISTING_MAX = 'capped_at_listing_max';
const TODAY_NEVER_RISES = 'today_never_rises';
const WITHIN_TOLERANCE = 'within_tolerance';
const CADENCE_PREVIOUS_STEP_NOT_PUSHED = 'cadence_previous_step_not_pushed';
const CADENCE_STEP_WITHIN_24H = 'cadence_step_within_24h';
const PACE_STEP = 'pace_step';
const CEILING_AT_TARGET = 'ceiling_at_target';
const CEILING_FOLLOWS_FLOOR = 'ceiling_follows_floor';
```

#### `Ritmo\Domain\Pricing\StepDirection` (enum)

```php
case Up = 'up';
case Down = 'down';
```

#### `Ritmo\Domain\Pricing\StepSize` (final class)

```php
public function __construct(RuleSet $rules);
public function maxPercent(int $daysOut, bool $weakAnchor = false): float;
public function next(float $current, float $target, int $daysOut, bool $weakAnchor = false): float;
public function direction(float $current, float $new): ?StepDirection;
```

#### `Ritmo\Domain\Pricing\TargetPercentile` (final class)

```php
public function __construct(RuleSet $rules);
public function forNight(Property $property, PaceStatus $status, int $daysOut): float;
public function isAheadNear(PaceStatus $status, int $daysOut): bool;
```

#### `Ritmo\Domain\Plan\NightState` (final class)

```php
public readonly ?float $price;
public readonly ?float $minPrice;
public readonly ?float $maxPrice;
public readonly ?int $minStay;
public readonly ?float $minPercent;
public readonly ?float $maxPercent;
public function __construct(?float $price = null, ?float $minPrice = null, ?float $maxPrice = null, ?int $minStay = null, ?float $minPercent = null, ?float $maxPercent = null);
public static function from(?PriceNight $price, ?DateOverride $override = null): self;
public function toArray(): array;
```

#### `Ritmo\Domain\Plan\PercentItem` (final class)

```php
public readonly PlanItem $item;
public readonly float $base;
public readonly ?int $minPercent;
public readonly ?int $maxPercent;
public function __construct(PlanItem $item, float $base, ?int $minPercent, ?int $maxPercent);
public function minAmount(int $decimals = 0): ?float;
public function maxAmount(int $decimals = 0): ?float;
public function sameAsInForce(): bool;
```

#### `Ritmo\Domain\Plan\PercentPlan` (final class)

```php
public readonly ValidatedPlan $plan;
public readonly array $items;
public readonly array $dropped;
public function __construct(ValidatedPlan $plan, array $items, array $dropped);
public function __serialize(): array;
public function __unserialize(array $data): void;
```

#### `Ritmo\Domain\Plan\PercentRounding` (final class)

```php
public function convert(PlanItem $item, RoomType $room): PercentItem;
public function apply(ValidatedPlan $plan, array $rooms): PercentPlan;
```

#### `Ritmo\Domain\Plan\Plan` (final class)

```php
public readonly DateTimeImmutable $today;
public readonly DateTimeImmutable $createdAt;
public readonly array $items;
public readonly string $propertyId;
public function __construct(string $propertyId, DateTimeImmutable $today, DateTimeImmutable $createdAt, array $items);
public static function fromDecisions(Property $property, Clock $clock, array $decisions, array $before = []): self;
public function count(): int;
public function without(array $indexes): self;
```

#### `Ritmo\Domain\Plan\PlanIssue` (final class)

```php
const ERROR = 'error';
const WARNING = 'warning';
const STALE_PLAN = 'stale_plan';
const UNKNOWN_ROOM = 'unknown_room';
const DUPLICATE_ITEM = 'duplicate_item';
const MISSING_REASON = 'missing_reason';
const PAST_DATE = 'past_date';
const RAISE_TODAY = 'raise_today';
const TODAY_WITHOUT_CURRENT_PRICE = 'today_without_current_price';
const BELOW_SAFETY_LIMIT = 'below_safety_limit';
const BELOW_HARD_FLOOR = 'below_hard_floor';
const BELOW_LISTING_MIN = 'below_listing_min';
const ABOVE_LISTING_MAX = 'above_listing_max';
const CEILING_BELOW_FLOOR = 'ceiling_below_floor';
const NO_EFFECT = 'no_effect';
const RISE_ABOVE_MAX_STEP = 'rise_above_max_step';
const NO_BEFORE = 'no_before';
public readonly string $severity;
public readonly string $code;
public readonly string $message;
public readonly ?int $itemIndex;
public readonly ?string $roomCode;
public readonly ?DateTimeImmutable $date;
public readonly array $params;
public function __construct(string $severity, string $code, string $message, ?int $itemIndex = null, ?string $roomCode = null, ?DateTimeImmutable $date = null, array $params = []);
public function isError(): bool;
```

#### `Ritmo\Domain\Plan\PlanItem` (final class)

```php
public readonly DateTimeImmutable $date;
public readonly string $roomCode;
public readonly ?float $minPrice;
public readonly ?float $maxPrice;
public readonly ?int $minStay;
public readonly string $reasonCode;
public readonly array $reasonParams;
public readonly ?NightState $before;
public function __construct(string $roomCode, DateTimeImmutable $date, ?float $minPrice = null, ?float $maxPrice = null, ?int $minStay = null, string $reasonCode = '', array $reasonParams = [], ?NightState $before = null);
public static function fromDecision(PaceDecision $decision, ?NightState $before = null): self;
public function key(): string;
public static function keyOf(string $roomCode, DateTimeInterface $night): string;
public function toArray(): array;
```

#### `Ritmo\Domain\Plan\ValidatedPlan` (final class)

```php
public readonly Plan $plan;
public readonly DateTimeImmutable $validatedAt;
public readonly DateTimeImmutable $today;
public readonly string $timezone;
public readonly string $rulesHash;
public readonly array $warnings;
public readonly array $bases;
public function isCurrent(Clock $clock): bool;
public function __serialize(): array;
public function __unserialize(array $data): void;
```

#### `Ritmo\Domain\Plan\ValidationResult` (final class)

```php
public readonly array $errors;
public readonly array $warnings;
public readonly ?ValidatedPlan $plan;
public function __construct(array $errors, array $warnings, ?ValidatedPlan $plan);
public function isValid(): bool;
public function errorCodes(): array;
public function warningCodes(): array;
public function rejectedItems(): array;
```

#### `Ritmo\Domain\Plan\Validator` (final class)

```php
const FLOOR_MARGIN = 1.0;
const LISTING_MAX_MARGIN = 0.5;
const SAME_AMOUNT = 1.0;
public function __construct(RuleSet $rules, Clock $clock);
public function validate(Plan $plan, Property $property, array $rooms): ValidationResult;
public static function sealed(ValidatedPlan $plan): bool;
```

#### `Ritmo\Domain\Report\BookedUnits` (final class)

```php
public static function byRoomNight(array $bookings, DateRange $range): array;
```

#### `Ritmo\Domain\Report\OccupancyReport` (final class)

```php
public readonly string $propertyId;
public readonly DateTimeImmutable $today;
public readonly array $rooms;
public readonly array $property;
public function __construct(string $propertyId, DateTimeImmutable $today, array $rooms, array $property);
public function windowsUnderGoal(): array;
```

#### `Ritmo\Domain\Report\OccupancyWindows` (final class)

```php
public function __construct(RuleSet $rules);
public function measure(Property $property, array $rooms, array $inventory, DateTimeInterface $today): OccupancyReport;
```

#### `Ritmo\Domain\Report\OverbookedNight` (final class)

```php
public readonly string $roomCode;
public readonly DateTimeImmutable $night;
public readonly int $available;
public function __construct(string $roomCode, DateTimeImmutable $night, int $available);
public function unitsOver(): int;
```

#### `Ritmo\Domain\Report\OverbookingDetector` (final class)

```php
public function detect(array $inventory): array;
```

#### `Ritmo\Domain\Report\PmsPricingMismatch` (final class)

```php
public function find(array $bookings, array $pricing, DateRange $range): array;
```

#### `Ritmo\Domain\Report\SoldMismatch` (final class)

```php
public readonly string $roomCode;
public readonly DateTimeImmutable $night;
public readonly int $pmsSold;
public readonly int $pricingSold;
public function __construct(string $roomCode, DateTimeImmutable $night, int $pmsSold, int $pricingSold);
public function difference(): int;
```

#### `Ritmo\Domain\Report\WindowOccupancy` (final class)

```php
public readonly int $days;
public readonly int $sold;
public readonly int $capacity;
public readonly ?float $percent;
public readonly float $goal;
public function __construct(int $days, int $sold, int $capacity, ?float $percent, float $goal);
public function meetsGoal(): ?bool;
```

<!-- END GENERATED SIGNATURES -->
