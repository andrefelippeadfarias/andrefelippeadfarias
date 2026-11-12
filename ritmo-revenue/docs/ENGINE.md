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

**The price the engine starts from** (`RuleInForce`): a price read before the last date rule was written (an old recalculation of the pricing system, or the price the PMS still holds) is brought within the floor and ceiling of the date rule in force before the engine sees it: under the floor (by more than 0.5) it becomes the floor, over the ceiling it becomes the ceiling, never under the floor. `RuleInForce::floor($rule, $base)` and `ceiling()` turn the rule into amounts (fixed, else the percentage over the base). The shadow run's decide stage applies it and, when the price moved, puts `price_within_rule_in_force` with the price read at the front of the item's trace. Reference: `pace.preco_no_vigente`.

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
| `price_within_rule_in_force` | `read` (the price read), `price` (the price the engine starts from), `floor_in_force`, `ceiling_in_force`. Added in front of the trace by the shadow run, not by the engine (see below). |
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
| `pace.preco_no_vigente` | `RuleInForce::price`, `floor`, `ceiling`: 630 prices against date rules of every kind |
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

#### `Ritmo\Domain\Calendar\Stay\ArrivalDepartureChange` (final class)

```php
public readonly DateTimeImmutable $night;
public readonly string $roomCode;
public readonly ChangeTarget $target;
public readonly StayReason $reason;
public readonly ?Restriction $before;
public readonly ?Restriction $after;
public readonly ?string $checkinMask;
public readonly ?string $checkoutMask;
public readonly bool $alsoPricing;
public readonly ?OrphanKind $orphan;
public readonly ?OrphanProof $proof;
public readonly bool $keepReason;
public static function forPms(string $roomCode, DateTimeImmutable $night, StayReason $reason, Restriction $before, Restriction $after, bool $alsoPricing = false, ?OrphanProof $proof = null): self;
public static function forPricing(string $roomCode, DateTimeImmutable $night, StayReason $reason, string $checkinMask, string $checkoutMask, bool $keepReason = false): self;
public function identity(): string;
```

#### `Ritmo\Domain\Calendar\Stay\ArrivalRule` (final class)

```php
public readonly bool $enabled;
public readonly ?DateTimeImmutable $validFrom;
public readonly ?DateTimeImmutable $validTo;
public readonly array $byWeekday;
public readonly array $propertyWeekdays;
public readonly array $roomWeekdays;
public readonly array $heldWeekdays;
public function __construct(bool $enabled = false, ?DateTimeImmutable $validFrom = null, ?DateTimeImmutable $validTo = null, array $byWeekday = [1, 1, 1, 1, 1, 1, 1], array $propertyWeekdays = [], array $roomWeekdays = [], array $heldWeekdays = []);
public function inForce(DateTimeInterface $night): bool;
public function stayFor(string $roomCode, string $propertyId, DateTimeInterface $night): ?int;
public function isHeld(string $roomCode, DateTimeInterface $night): bool;
```

#### `Ritmo\Domain\Calendar\Stay\BatchPolicy` (final class)

```php
public readonly int $urgentDays;
public readonly int $maxRanges;
public function __construct(int $urgentDays = 14, int $maxRanges = 100);
```

#### `Ritmo\Domain\Calendar\Stay\ChangeTarget` (enum)

```php
case Pms = 'pms';
case Pricing = 'pricing';
```

#### `Ritmo\Domain\Calendar\Stay\ClosedDayRule` (final class)

```php
public readonly int $weekday;
public readonly Restriction $restriction;
public readonly array $rooms;
public readonly array $exceptRooms;
public readonly ?int $minStay;
public readonly array $openDates;
public readonly OrphanSide $orphanSide;
public function __construct(int $weekday, Restriction $restriction, array $rooms = [], array $exceptRooms = [], ?int $minStay = null, array $openDates = [], OrphanSide $orphanSide = \Ritmo\Domain\Calendar\Stay\OrphanSide::__set_state(array(
   'enabled' => false,
   'onHolidays' => false,
   'whenClosedDaySoldOut' => 
  \Ritmo\Domain\Model\Restriction::NoCheckin,
   'whenPreviousSoldOut' => 
  \Ritmo\Domain\Model\Restriction::NoCheckout,
)));
public function appliesTo(string $roomCode, DateTimeInterface $night): bool;
public function openDate(DateTimeInterface $night): ?Restriction;
```

#### `Ritmo\Domain\Calendar\Stay\ClosedDaySide` (final class)

```php
public readonly Restriction $restriction;
public readonly StayReason $reason;
public readonly bool $soldOutClosedDay;
public readonly bool $freeClosedDay;
public readonly ?OrphanProof $proof;
public static function of(StayRules $rules, string $room, ClosedDayRule $closed, InventoryNight $night, ?InventoryNight $previous, Occupancy $occupancy, DateTimeImmutable $today): self;
```

#### `Ritmo\Domain\Calendar\Stay\InventoryAudit` (final class)

```php
public function __construct(StayRules $rules, StaySyncCapability $capability);
public function audit(array $inventory, DateTimeImmutable $from, DateTimeImmutable $to, DateTimeImmutable $today, ?DateTimeImmutable $readAt): array;
```

#### `Ritmo\Domain\Calendar\Stay\NightStayDecision` (final class)

```php
public readonly array $findings;
public function __construct(StayRules $rules, string $room, DateTimeImmutable $night, Occupancy $occupancy, DateTimeImmutable $today);
public function decide(int $stay, bool $rulesRead, ?int $ruleMinStay, bool $stayOnly): self;
public function change(int $stayBefore, ?string $holiday, bool $keepReason): ?StayChange;
public function isOrphan(): bool;
```

#### `Ritmo\Domain\Calendar\Stay\Occupancy` (final class)

```php
public static function fromInventory(array $nights, int $units, ?DateTimeImmutable $readAt): self;
public static function fromPricing(array $nights, int $units): self;
public function free(DateTimeImmutable $night): bool;
public function soldOut(DateTimeImmutable $night): bool;
public function proof(DateTimeImmutable $night, string $neighbour): OrphanProof;
public function proofPreviousPast(DateTimeImmutable $closedDay): OrphanProof;
```

#### `Ritmo\Domain\Calendar\Stay\OpenDayRule` (final class)

```php
public readonly int $weekday;
public readonly array $rooms;
public function __construct(int $weekday, array $rooms);
public function appliesTo(string $roomCode, DateTimeInterface $night): bool;
```

#### `Ritmo\Domain\Calendar\Stay\OrphanKind` (enum)

```php
case NextNight = 'next_night';
case ClosedDay = 'closed_day';
```

#### `Ritmo\Domain\Calendar\Stay\OrphanPolicy` (final class)

```php
public readonly bool $nextNight;
public readonly bool $onHolidays;
public function __construct(bool $nextNight = false, bool $onHolidays = false);
```

#### `Ritmo\Domain\Calendar\Stay\OrphanProof` (final class)

```php
const SOURCE_PMS = 'pms';
const SOURCE_PRICING = 'pricing';
const NEXT = 'next';
const PREVIOUS = 'previous';
public readonly ?DateTimeImmutable $previousPast;
public readonly string $source;
public readonly string $neighbour;
public readonly ?int $units;
public readonly ?int $nightAvailable;
public readonly ?int $neighbourAvailable;
public readonly ?int $nightSold;
public readonly ?int $nightTotal;
public readonly ?int $neighbourSold;
public readonly ?int $neighbourTotal;
public readonly ?DateTimeImmutable $readAt;
public function __construct(string $source, string $neighbour, ?int $units = null, ?int $nightAvailable = null, ?int $neighbourAvailable = null, ?int $nightSold = null, ?int $nightTotal = null, ?int $neighbourSold = null, ?int $neighbourTotal = null, ?DateTimeImmutable $readAt = null, ?DateTimeImmutable $previousPast = null);
public function withPreviousPast(DateTimeImmutable $night, bool $dropPricingNeighbour): self;
public function toArray(): array;
```

#### `Ritmo\Domain\Calendar\Stay\OrphanSide` (final class)

```php
public readonly bool $enabled;
public readonly bool $onHolidays;
public readonly Restriction $whenClosedDaySoldOut;
public readonly Restriction $whenPreviousSoldOut;
public function __construct(bool $enabled = false, bool $onHolidays = false, Restriction $whenClosedDaySoldOut = Restriction::NoCheckin, Restriction $whenPreviousSoldOut = Restriction::NoCheckout);
```

#### `Ritmo\Domain\Calendar\Stay\Package` (final class)

```php
public readonly string $name;
public readonly array $rooms;
public readonly array $nights;
public function __construct(string $name, array $rooms, array $nights);
public function night(string $roomCode, DateTimeInterface $night): ?PackageNight;
```

#### `Ritmo\Domain\Calendar\Stay\PackageNight` (final class)

```php
public readonly DateTimeImmutable $date;
public readonly ?int $minStay;
public readonly ?int $maxStay;
public readonly ?Restriction $restriction;
public function __construct(DateTimeImmutable $date, ?int $minStay = null, ?int $maxStay = null, ?Restriction $restriction = null);
```

#### `Ritmo\Domain\Calendar\Stay\PlannedStay` (final class)

```php
public readonly ?int $minStay;
public readonly bool $orphan;
public function __construct(?int $minStay, bool $orphan = false);
public static function undecided(): self;
```

#### `Ritmo\Domain\Calendar\Stay\PricingStayEntry` (final class)

```php
public readonly DateTimeImmutable $night;
public readonly string $roomCode;
public readonly array $findings;
public readonly ?int $stayBefore;
public readonly ?string $holiday;
public readonly ?StayChange $stay;
public readonly ?ArrivalDepartureChange $masks;
public function __construct(string $roomCode, DateTimeImmutable $night, array $findings, ?int $stayBefore = null, ?string $holiday = null, ?StayChange $stay = null, ?ArrivalDepartureChange $masks = null);
public function issues(): array;
public function accepted(): array;
public function hasItem(): bool;
```

#### `Ritmo\Domain\Calendar\Stay\PricingStayNight` (final class)

```php
public readonly DateTimeImmutable $date;
public readonly int $minStay;
public readonly int $sold;
public readonly int $total;
public readonly ?string $checkinMask;
public readonly ?string $checkoutMask;
public readonly bool $masksEnabled;
public function __construct(DateTimeImmutable $date, int $minStay, int $sold, int $total, ?string $checkinMask = null, ?string $checkoutMask = null, bool $masksEnabled = true);
public static function fromPriceNight(PriceNight $night, ?string $checkinMask = null, ?string $checkoutMask = null, bool $masksEnabled = true): self;
public function isSoldOut(): bool;
```

#### `Ritmo\Domain\Calendar\Stay\PricingStayPlan` (final class)

```php
public function __construct(StayRules $rules);
public function plan(string $room, array $calendar, Occupancy $occupancy, DateTimeImmutable $from, DateTimeImmutable $to, DateTimeImmutable $today, ?array $effective = null, ?array $dateRules = null, bool $stayOnly = true): array;
```

#### `Ritmo\Domain\Calendar\Stay\PricingSync` (final class)

```php
const EVERY_ROOM = '*';
public readonly array $minStayRooms;
public readonly array $maxStayRooms;
public readonly array $arrivalDepartureRooms;
public function __construct(array $minStayRooms = [], array $maxStayRooms = [], array $arrivalDepartureRooms = []);
public function pushes(StaySyncField $field, string $roomCode, StaySyncCapability $capability): bool;
```

#### `Ritmo\Domain\Calendar\Stay\RestrictedNight` (final class)

```php
public readonly DateTimeImmutable $night;
public readonly Restriction $restriction;
public function __construct(DateTimeImmutable $night, Restriction $restriction);
```

#### `Ritmo\Domain\Calendar\Stay\RewrittenStay` (final class)

```php
public readonly ?int $minStay;
public readonly ?string $checkoutMask;
public readonly ?OrphanKind $orphan;
public readonly ?OrphanProof $proof;
public function __construct(?int $minStay, ?string $checkoutMask, ?OrphanKind $orphan = null, ?OrphanProof $proof = null);
```

#### `Ritmo\Domain\Calendar\Stay\RoomAudit` (final class)

```php
public readonly string $roomCode;
public readonly array $arrivalChanges;
public readonly array $closedDayStayBelow;
public readonly array $otherRestricted;
public readonly array $orphanStays;
public readonly array $packageStays;
public readonly array $arrivalStays;
public function __construct(string $roomCode, array $arrivalChanges, array $closedDayStayBelow, array $otherRestricted, array $orphanStays, array $packageStays, array $arrivalStays);
public function pmsOrphanStays(): array;
```

#### `Ritmo\Domain\Calendar\Stay\RoomFindings` (final class)

```php
public readonly array $arrivalChanges;
public readonly array $closedDayStayBelow;
public readonly array $otherRestricted;
public readonly array $orphanStays;
public readonly array $packageStays;
public readonly array $arrivalStays;
public readonly string $room;
public function __construct(string $room);
public function toAudit(): RoomAudit;
```

#### `Ritmo\Domain\Calendar\Stay\Season` (final class)

```php
public readonly string $name;
public readonly DateRange $nights;
public readonly array $weekdays;
public readonly ?int $minStay;
public function __construct(string $name, DateRange $nights, array $weekdays, ?int $minStay);
public function covers(DateTimeInterface $night): bool;
```

#### `Ritmo\Domain\Calendar\Stay\StayBatch` (final class)

```php
public function __construct(BatchPolicy $policy, array $propertyOfRoom);
public function single(array $audit, DateTimeImmutable $today, bool $stayOnly): array;
public function blocks(array $changes): array;
```

#### `Ritmo\Domain\Calendar\Stay\StayChange` (final class)

```php
public readonly DateTimeImmutable $night;
public readonly string $roomCode;
public readonly ChangeTarget $target;
public readonly StayReason $reason;
public readonly ?int $minStay;
public readonly ?int $minStayBefore;
public readonly ?int $maxStay;
public readonly ?int $maxStayBefore;
public readonly bool $alsoPricing;
public readonly ?OrphanKind $orphan;
public readonly ?OrphanProof $proof;
public readonly ?string $holiday;
public readonly ?string $season;
public readonly bool $keepReason;
public function __construct(string $roomCode, DateTimeImmutable $night, ChangeTarget $target, StayReason $reason, ?int $minStay = null, ?int $minStayBefore = null, ?int $maxStay = null, ?int $maxStayBefore = null, bool $alsoPricing = false, ?OrphanKind $orphan = null, ?OrphanProof $proof = null, ?string $holiday = null, ?string $season = null, bool $keepReason = false);
public function identity(): string;
```

#### `Ritmo\Domain\Calendar\Stay\StayConfig` (final class)

```php
public readonly ArrivalRule $arrival;
public readonly OrphanPolicy $orphan;
public readonly int $holidayMinStay;
public readonly array $closedDays;
public readonly array $openDays;
public readonly array $packages;
public readonly array $seasons;
public readonly PricingSync $pricingSync;
public readonly array $keepReasonMarkers;
public readonly BatchPolicy $batch;
public function __construct(ArrivalRule $arrival = \Ritmo\Domain\Calendar\Stay\ArrivalRule::__set_state(array(
   'enabled' => false,
   'validFrom' => NULL,
   'validTo' => NULL,
   'byWeekday' => 
  array (
    0 => 1,
    1 => 1,
    2 => 1,
    3 => 1,
    4 => 1,
    5 => 1,
    6 => 1,
  ),
   'propertyWeekdays' => 
  array (
  ),
   'roomWeekdays' => 
  array (
  ),
   'heldWeekdays' => 
  array (
  ),
)), OrphanPolicy $orphan = \Ritmo\Domain\Calendar\Stay\OrphanPolicy::__set_state(array(
   'nextNight' => false,
   'onHolidays' => false,
)), int $holidayMinStay = 1, array $closedDays = [], array $openDays = [], array $packages = [], array $seasons = [], PricingSync $pricingSync = \Ritmo\Domain\Calendar\Stay\PricingSync::__set_state(array(
   'minStayRooms' => 
  array (
  ),
   'maxStayRooms' => 
  array (
  ),
   'arrivalDepartureRooms' => 
  array (
  ),
)), array $keepReasonMarkers = [], BatchPolicy $batch = \Ritmo\Domain\Calendar\Stay\BatchPolicy::__set_state(array(
   'urgentDays' => 14,
   'maxRanges' => 100,
)));
public static function fields(): array;
public static function fromArray(array $data): self;
```

#### `Ritmo\Domain\Calendar\Stay\StayFields` (final class)

```php
const ROOT = 'stay';
public static function all(): array;
```

#### `Ritmo\Domain\Calendar\Stay\StayFinding` (enum)

```php
case NoPricingData = 'no_pricing_data';
case StayUp = 'stay_up';
case StayDown = 'stay_down';
case OrphanNight = 'orphan_night';
case ExplicitStay = 'explicit_stay';
case ClosedDayOrphanToday = 'closed_day_orphan_today';
case OwnStayOutsideStayPlan = 'own_stay_outside_stay_plan';
case OpenDayMasks = 'open_day_masks';
case Held = 'held';
case IsolatedDay = 'isolated_day';
case OrphanAccepted = 'orphan_accepted';
case ClosedDayOrphanAccepted = 'closed_day_orphan_accepted';
public function isAccepted(): bool;
```

#### `Ritmo\Domain\Calendar\Stay\StayHook` (final class)

```php
public function __construct(StayRules $rules);
public function nightStay(string $room, DateTimeImmutable $night, ?int $current, Occupancy $occupancy): array;
public function closedDayOrphanProof(string $room, DateTimeImmutable $night, Occupancy $occupancy, ?DateTimeImmutable $today): ?OrphanProof;
public function rewrite(string $room, DateTimeImmutable $night, ?int $minStay, ?string $checkoutMask, Occupancy $occupancy, ?DateTimeImmutable $today): RewrittenStay;
```

#### `Ritmo\Domain\Calendar\Stay\StayReason` (enum)

```php
case ClosedDay = 'closed_day';
case ClosedDayOpenDate = 'closed_day_open_date';
case OrphanSideClosedDaySoldOut = 'orphan_side_closed_day_sold_out';
case OrphanSidePreviousSoldOut = 'orphan_side_previous_sold_out';
case OrphanSidePreviousPast = 'orphan_side_previous_past';
case OpenDay = 'open_day';
case Package = 'package';
case ArrivalDay = 'arrival_day';
case OrphanNight = 'orphan_night';
case ClosedDayOrphan = 'closed_day_orphan';
case ExplicitRule = 'explicit_rule';
case OwnStay = 'own_stay';
```

#### `Ritmo\Domain\Calendar\Stay\StayRules` (final class)

```php
public function __construct(StayConfig $config, HolidayCalendar $calendar, array $rooms);
public function config(): StayConfig;
public function room(string $roomCode): RoomType;
public function holiday(DateTimeImmutable $night): ?Holiday;
public function requiredStay(string $roomCode, DateTimeImmutable $night): ?int;
public function plannedStay(string $roomCode, DateTimeImmutable $night, ?int $current, bool $free, bool $nextSoldOut): PlannedStay;
public function arrivalDecides(string $roomCode, DateTimeImmutable $night): bool;
public function isHeld(string $roomCode, DateTimeImmutable $night): bool;
public function orphanDecides(string $roomCode, DateTimeImmutable $night): bool;
public function orphanAllowed(string $roomCode, DateTimeImmutable $night, bool $closedDaySide): bool;
public function packageNight(string $roomCode, DateTimeImmutable $night): ?PackageNight;
public function isPackageNight(string $roomCode, DateTimeImmutable $night): bool;
public function closedDay(string $roomCode, DateTimeImmutable $night): ?ClosedDayRule;
public function openDayApplies(string $roomCode, DateTimeImmutable $night): bool;
public function closedDayWithPreviousPast(string $roomCode, DateTimeImmutable $night, ?DateTimeImmutable $today): bool;
public function arrivalWeekdayStay(string $roomCode, DateTimeImmutable $night): ?int;
public function seasonOutsideHolidays(DateTimeImmutable $night): ?Season;
```

#### `Ritmo\Domain\Calendar\Stay\StaySyncCapability` (final class)

```php
public readonly array $fields;
public function __construct(StaySyncField $fields);
public static function none(): self;
public function supports(StaySyncField $field): bool;
```

#### `Ritmo\Domain\Calendar\Stay\StaySyncField` (enum)

```php
case MinStay = 'min_stay';
case MaxStay = 'max_stay';
case ArrivalDeparture = 'arrival_departure';
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

#### `Ritmo\Domain\Pricing\Cadence\CadenceConfig` (final class)

```php
const ROOT = 'cadence';
public readonly int $minHoursBetweenSteps;
public readonly bool $requireChannelArrival;
public readonly bool $oppositeDirectionImmediate;
public readonly bool $releaseOnBooking;
public readonly bool $newOrderImmediateOnce;
public readonly bool $firstLadderStepImmediate;
public readonly bool $relationViolationBypass;
public function __construct(int $minHoursBetweenSteps = 24, bool $requireChannelArrival = true, bool $oppositeDirectionImmediate = true, bool $releaseOnBooking = true, bool $newOrderImmediateOnce = true, bool $firstLadderStepImmediate = true, bool $relationViolationBypass = true);
public static function fromArray(array $data): self;
public static function fields(): array;
```

#### `Ritmo\Domain\Pricing\Cadence\CadenceVerdict` (final class)

```php
const NOT_ON_CHANNEL = 'CADENCE_NOT_ON_CHANNEL';
const WITHIN_INTERVAL = 'CADENCE_WITHIN_24H';
public readonly bool $allowed;
public readonly ?string $code;
public readonly ?StepRecord $lastStep;
public static function allow(?StepRecord $lastStep = null): self;
public static function hold(string $code, StepRecord $lastStep): self;
```

#### `Ritmo\Domain\Pricing\Cadence\NoEffectConfig` (final class)

```php
const ROOT = 'no_effect';
public readonly bool $dropRoundingOnly;
public readonly float $moneySlack;
public function __construct(bool $dropRoundingOnly = true, float $moneySlack = 1.0);
public static function fromArray(array $data): self;
public function filter(): NoEffectFilter;
public static function fields(): array;
```

#### `Ritmo\Domain\Pricing\Cadence\NoEffectFilter` (final class)

```php
public function __construct(bool $dropRoundingOnly = true, float $moneySlack = 1.0);
public function filter(array $items, array $rooms, array $live, array $overrides, array $deleteFirst = []): NoEffectResult;
```

#### `Ritmo\Domain\Pricing\Cadence\NoEffectResult` (final class)

```php
public readonly array $kept;
public readonly array $noEffect;
public readonly array $roundingOnly;
public function __construct(array $kept, array $noEffect, array $roundingOnly);
```

#### `Ritmo\Domain\Pricing\Cadence\SoldBase` (enum)

```php
case Room = 'room';
case Property = 'property';
```

#### `Ritmo\Domain\Pricing\Cadence\StepCadence` (final class)

```php
public function __construct(CadenceConfig $config = \Ritmo\Domain\Pricing\Cadence\CadenceConfig::__set_state(array(
   'minHoursBetweenSteps' => 24,
   'requireChannelArrival' => true,
   'oppositeDirectionImmediate' => true,
   'releaseOnBooking' => true,
   'newOrderImmediateOnce' => true,
   'firstLadderStepImmediate' => true,
   'relationViolationBypass' => true,
)));
public function permits(StepLedger $ledger, string $room, DateTimeImmutable $night, StepDirection $direction, DateTimeImmutable $now, ?int $sold = null, SoldBase $base = SoldBase::Room, ?int $total = null): CadenceVerdict;
public function newOrderBypass(StepLedger $ledger, string $room, DateTimeImmutable $night, ?OwnerOrder $order, DateTimeImmutable $today): bool;
```

#### `Ritmo\Domain\Pricing\Cadence\StepLedger` (final class)

```php
public function __construct(array $records = []);
public function records(): array;
public function lastStep(string $room, DateTimeImmutable $night): ?StepRecord;
public function hasLadderStep(string $room, DateTimeImmutable $night): bool;
public function hasStepSince(string $room, DateTimeImmutable $night, DateTimeImmutable $instant): bool;
public function record(iterable $written, DateTimeImmutable $now): self;
public function markOnChannel(DateTimeImmutable|array $pushedAt): self;
public function pendingCount(): int;
```

#### `Ritmo\Domain\Pricing\Cadence\StepProposal` (final class)

```php
public readonly DateTimeImmutable $night;
public readonly string $roomCode;
public readonly string $sourceStatus;
public readonly ?StepDirection $direction;
public readonly float $priceFrom;
public readonly float $priceTo;
public readonly int $targetSent;
public readonly ?int $sold;
public readonly SoldBase $soldBase;
public readonly ?int $soldTotal;
public function __construct(string $roomCode, DateTimeImmutable $night, string $sourceStatus, ?StepDirection $direction, float $priceFrom, float $priceTo, int $targetSent, ?int $sold, SoldBase $soldBase, ?int $soldTotal);
public function toArray(): array;
```

#### `Ritmo\Domain\Pricing\Cadence\StepRecord` (final class)

```php
const LADDER = 'ladder';
public readonly DateTimeImmutable $night;
public readonly DateTimeImmutable $writtenAt;
public readonly ?DateTimeImmutable $onChannelAt;
public readonly string $roomCode;
public readonly float $priceFrom;
public readonly float $priceTo;
public readonly StepDirection $direction;
public readonly string $sourceStatus;
public readonly ?int $sold;
public readonly SoldBase $soldBase;
public readonly ?int $soldTotal;
public readonly bool $minutePrecision;
public function __construct(string $roomCode, DateTimeImmutable $night, float $priceFrom, float $priceTo, StepDirection $direction, string $sourceStatus, ?int $sold, SoldBase $soldBase, ?int $soldTotal, DateTimeImmutable $writtenAt, bool $minutePrecision = false, ?DateTimeImmutable $onChannelAt = null);
public static function fromArray(array $row): self;
public function toArray(): array;
public function isLadderStep(): bool;
public function isFor(string $room, DateTimeImmutable $night): bool;
public function writtenAtLatest(): DateTimeImmutable;
public function onChannel(DateTimeImmutable $pushedAt): self;
public static function instant(string $text): DateTimeImmutable;
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

#### `Ritmo\Domain\Pricing\Ladder\BatchImportDetector` (final class)

```php
public function __construct(int $minSameMinute = 3);
public function batchNights(array $bookings, ?DateTimeImmutable $since = null): array;
```

#### `Ritmo\Domain\Pricing\Ladder\BetweenRule` (final class)

```php
public readonly string $room;
public readonly string $low;
public readonly string $high;
public readonly float $position;
public function __construct(string $room, string $low, string $high, float $position);
```

#### `Ritmo\Domain\Pricing\Ladder\ConfigReader` (final class)

```php
public function __construct(array $fields);
public function open(string $root, array $data): array;
public function int(mixed $value, string $path): int;
public function nullableInt(mixed $value, string $path): ?int;
public function float(mixed $value, string $path): float;
public function nullableFloat(mixed $value, string $path): ?float;
public function bool(mixed $value, string $path): bool;
public function string(mixed $value, string $path): string;
public function nullableString(mixed $value, string $path): ?string;
public function choice(mixed $value, string $path): string|int;
public function date(mixed $value, string $path): ?DateTimeImmutable;
public function items(mixed $value, string $path): array;
public function add(string $path, string $code, string $message): void;
public function finish(string $what): void;
```

#### `Ritmo\Domain\Pricing\Ladder\Derivation` (final class)

```php
public readonly string $fromRoom;
public readonly float $multiplier;
public function __construct(string $fromRoom, float $multiplier);
```

#### `Ritmo\Domain\Pricing\Ladder\DropRule` (final class)

```php
public readonly bool $enabled;
public readonly int $windowDays;
public readonly float $occupancyBelow;
public readonly float $percent;
public readonly bool $skipInsideWindowAtActivation;
public function __construct(bool $enabled = false, int $windowDays = 7, float $occupancyBelow = 0.6, float $percent = 10.0, bool $skipInsideWindowAtActivation = true);
```

#### `Ritmo\Domain\Pricing\Ladder\EffectiveValue` (final class)

```php
public static function floor(float $amount, RoomType $room): float;
public static function ceiling(float $amount, RoomType $room): float;
```

#### `Ritmo\Domain\Pricing\Ladder\LadderActivation` (final class)

```php
public static function isActive(LadderStrategy $strategy, string $room, DateTimeInterface $night, OrderBook $orders): bool;
```

#### `Ritmo\Domain\Pricing\Ladder\LadderConfig` (final class)

```php
const ROOT = 'ladder';
public readonly bool $enabled;
public readonly array $strategies;
public function __construct(bool $enabled = false, array $strategies = []);
public function activeStrategies(): array;
public static function fromArray(array $data): self;
public static function fields(): array;
```

#### `Ritmo\Domain\Pricing\Ladder\LadderFields` (final class)

```php
public static function all(): array;
```

#### `Ritmo\Domain\Pricing\Ladder\LadderHardFloor` (final class)

```php
const SAFETY_LIMIT = 'safety_limit';
const HOLIDAY_SAFETY_FLOOR = 'holiday_safety_floor';
public function __construct(Floors $floors, HolidayCalendar $calendar, PriceFactor $factors, FloorRule $holidayFloor);
public function for(RoomType $room, DateTimeInterface $night): array;
public function safetyLimit(RoomType $room): float;
```

#### `Ritmo\Domain\Pricing\Ladder\LadderItem` (final class)

```php
const REASON = 'LADDER_PRICE';
public readonly DateTimeImmutable $night;
public readonly string $roomCode;
public readonly float $minPrice;
public readonly ?float $maxPrice;
public readonly float $priceBefore;
public readonly array $reasonCodes;
public readonly string $strategyId;
public readonly ?string $authorityOrderId;
public readonly StepProposal $step;
public function __construct(string $roomCode, DateTimeImmutable $night, float $minPrice, ?float $maxPrice, float $priceBefore, array $reasonCodes, string $strategyId, ?string $authorityOrderId, StepProposal $step);
public function toPlanItem(): PlanItem;
```

#### `Ritmo\Domain\Pricing\Ladder\LadderItemBuilder` (final class)

```php
public function __construct(LadderStrategy $strategy, StepCadence $cadence, CadenceConfig $cadenceConfig, float $tolerance, float $moneySlack = 1.0, float $percentSlack = 0.01);
public function build(RoomType $room, LadderRoomQuote $quote, int $daysOut, PriceNight $live, ?DateOverride $override, StepLedger $ledger, DateTimeImmutable $now, bool $inFilter, float $listingTop): LadderOutcome;
```

#### `Ritmo\Domain\Pricing\Ladder\LadderLine` (final class)

```php
const TODAY_NEVER_UP = 'LADDER_TODAY_NEVER_UP';
const OUT_OF_NIGHT_FILTER = 'OUT_OF_NIGHT_FILTER';
public readonly DateTimeImmutable $night;
public readonly string $roomCode;
public readonly int $daysOut;
public readonly string $nightType;
public readonly int $sold;
public readonly int $units;
public readonly int $soldPercent;
public readonly int $targetPublic;
public readonly int $targetSent;
public readonly float $live;
public readonly int $livePublic;
public readonly int $new;
public readonly ?StepDirection $direction;
public readonly ?string $hold;
public function __construct(string $roomCode, DateTimeImmutable $night, int $daysOut, string $nightType, int $sold, int $units, int $soldPercent, int $targetPublic, int $targetSent, float $live, int $livePublic, int $new, ?StepDirection $direction, ?string $hold = null);
public function withHold(string $hold): self;
```

#### `Ritmo\Domain\Pricing\Ladder\LadderNight` (final class)

```php
public readonly DateTimeImmutable $night;
public readonly array $quotes;
public readonly bool $rulerGap;
public function __construct(DateTimeImmutable $night, array $quotes = [], bool $rulerGap = false);
public function quote(string $room): ?LadderRoomQuote;
public function with(LadderRoomQuote $quote): self;
```

#### `Ritmo\Domain\Pricing\Ladder\LadderNightEvaluator` (final class)

```php
public function __construct(SalesLadder $ladder, MarketRuler $ruler, LadderHardFloor $hardFloors, LadderRelations $relations, ?Closure $fallbackNightType = null);
public function evaluate(DateTimeImmutable $night, int $daysOut, array $rows, OrderBook $orders, array $blocked = []): LadderNight;
public function acts(string $room, DateTimeImmutable $night, OrderBook $orders, array $blocked = []): bool;
```

#### `Ritmo\Domain\Pricing\Ladder\LadderOutcome` (final class)

```php
public readonly ?LadderItem $item;
public readonly LadderLine $line;
public function __construct(?LadderItem $item, LadderLine $line);
```

#### `Ritmo\Domain\Pricing\Ladder\LadderQuote` (final class)

```php
public readonly float $public;
public readonly ?float $opening;
public readonly ?float $ceiling;
public readonly int $steps;
public readonly bool $dropped;
public readonly bool $derived;
public function __construct(float $public, ?float $opening, ?float $ceiling, int $steps, bool $dropped, bool $derived = false);
```

#### `Ritmo\Domain\Pricing\Ladder\LadderReferences` (final class)

```php
public function __construct(array $rows, array $blocked = [], ?array $reals = null);
public function valueOf(LadderNight $night, string $room): ?float;
public function sells(string $room): bool;
```

#### `Ritmo\Domain\Pricing\Ladder\LadderRelations` (final class)

```php
public function __construct(SalesLadder $ladder, HolidayCalendar $calendar, array $weekendDays, float $moneySlack = 1.0, float $percentSlack = 0.01);
public function apply(LadderNight $night, LadderReferences $refs): LadderNight;
```

#### `Ritmo\Domain\Pricing\Ladder\LadderRoomQuote` (final class)

```php
const RELATION_BETWEEN = 'RELATION_BETWEEN';
const RELATION_PREMIUM_OVER_MAX = 'RELATION_PREMIUM_OVER_MAX';
public readonly string $roomCode;
public readonly string $nightType;
public readonly float $sent;
public readonly float $public;
public readonly ?float $opening;
public readonly int $steps;
public readonly bool $dropped;
public readonly int $sold;
public readonly int $total;
public readonly float $hardFloor;
public readonly string $hardFloorLabel;
public readonly float $factor;
public readonly bool $derived;
public readonly ?array $limits;
public readonly ?float $baseSent;
public readonly array $relationReasons;
public function __construct(string $roomCode, string $nightType, float $sent, float $public, ?float $opening, int $steps, bool $dropped, int $sold, int $total, float $hardFloor, string $hardFloorLabel, float $factor, bool $derived = false, ?array $limits = null, ?float $baseSent = null, array $relationReasons = []);
public function withRelations(float $sent, ?array $limits, float $baseSent, array $reasons): self;
```

#### `Ritmo\Domain\Pricing\Ladder\LadderRule` (final class)

```php
public readonly array $nightTypes;
public readonly array $rooms;
public readonly array $roomTags;
public readonly ?RulerPoint $opening;
public readonly bool $capAtReference;
public readonly ?RulerPoint $ceiling;
public readonly ?Derivation $derive;
public readonly bool $applyQualityFloor;
public readonly bool $applyDrop;
public function __construct(array $nightTypes, array $rooms, array $roomTags, ?RulerPoint $opening, bool $capAtReference, ?RulerPoint $ceiling, ?Derivation $derive, bool $applyQualityFloor, bool $applyDrop);
public function matches(RoomType $room, string $nightType): bool;
```

#### `Ritmo\Domain\Pricing\Ladder\LadderSteps` (final class)

```php
public readonly float $triggerFraction;
public readonly float $percentPerUnit;
public function __construct(float $triggerFraction = 0.5, float $percentPerUnit = 5.0);
```

#### `Ritmo\Domain\Pricing\Ladder\LadderStrategy` (final class)

```php
const MODE_STEPPED = 'stepped';
const MODE_PROPORTIONAL = 'proportional';
public readonly string $id;
public readonly string $label;
public readonly bool $enabled;
public readonly string $mode;
public readonly ?DateTimeImmutable $validFrom;
public readonly ?DateTimeImmutable $validTo;
public readonly ?DateTimeImmutable $activatedOn;
public readonly ?string $authorityOrderId;
public readonly bool $lockCeiling;
public readonly LadderSteps $steps;
public readonly DropRule $drop;
public readonly bool $weekendFloorOverride;
public readonly array $weekendFloorWeekdays;
public readonly array $rules;
public readonly array $between;
public readonly array $premium;
public readonly LastMinuteFloorRule $lastMinuteFloor;
public readonly ProportionalRule $proportional;
public function __construct(string $id, string $label = '', bool $enabled = false, string $mode = 'stepped', ?DateTimeImmutable $validFrom = null, ?DateTimeImmutable $validTo = null, ?DateTimeImmutable $activatedOn = null, ?string $authorityOrderId = null, bool $lockCeiling = true, LadderSteps $steps = \Ritmo\Domain\Pricing\Ladder\LadderSteps::__set_state(array(
   'triggerFraction' => 0.5,
   'percentPerUnit' => 5.0,
)), DropRule $drop = \Ritmo\Domain\Pricing\Ladder\DropRule::__set_state(array(
   'enabled' => false,
   'windowDays' => 7,
   'occupancyBelow' => 0.6,
   'percent' => 10.0,
   'skipInsideWindowAtActivation' => true,
)), bool $weekendFloorOverride = false, array $weekendFloorWeekdays = [4, 5], array $rules = [], array $between = [], array $premium = [], LastMinuteFloorRule $lastMinuteFloor = \Ritmo\Domain\Pricing\Ladder\LastMinuteFloorRule::__set_state(array(
   'enabled' => false,
   'nightTypes' => 
  array (
  ),
   'excludedWeekdays' => 
  array (
  ),
   'skipHolidays' => true,
   'windowDays' => 7,
   'series' => NULL,
   'percentile' => 50.0,
   'rooms' => 
  array (
  ),
)), ProportionalRule $proportional = \Ritmo\Domain\Pricing\Ladder\ProportionalRule::__set_state(array(
   'fallbackStepPercent' => 5.0,
   'bandRoom' => NULL,
   'bandReferenceRoom' => NULL,
   'bandFactor' => 0.9,
   'bandCapRooms' => 
  array (
  ),
   'bandCapDiscount' => 0.1,
   'temporaryFloor' => NULL,
   'temporaryFloorUntil' => NULL,
)));
public function covers(DateTimeInterface $night): bool;
public function ruleFor(RoomType $room, string $nightType): ?LadderRule;
```

#### `Ritmo\Domain\Pricing\Ladder\LastMinuteFloorRule` (final class)

```php
public readonly bool $enabled;
public readonly array $nightTypes;
public readonly array $excludedWeekdays;
public readonly bool $skipHolidays;
public readonly int $windowDays;
public readonly ?string $series;
public readonly float $percentile;
public readonly array $rooms;
public function __construct(bool $enabled = false, array $nightTypes = [], array $excludedWeekdays = [], bool $skipHolidays = true, int $windowDays = 7, ?string $series = null, float $percentile = 50.0, array $rooms = []);
```

#### `Ritmo\Domain\Pricing\Ladder\LastMinuteMarketFloor` (final class)

```php
public function __construct(SalesLadder $ladder, MarketRuler $ruler, LadderHardFloor $hardFloors, HolidayCalendar $calendar);
public function floorFor(string $room, DateTimeImmutable $night, int $daysOut, OrderBook $orders): ?array;
```

#### `Ritmo\Domain\Pricing\Ladder\MarketRuler` (final class)

```php
public function __construct(array $nights = []);
public function night(DateTimeInterface $night): ?RulerNight;
public static function fromArray(array $nights): self;
```

#### `Ritmo\Domain\Pricing\Ladder\MarketTopTable` (final class)

```php
public function __construct(array $periods, array $references, array $weekendDays);
public function topFor(DateTimeInterface $night): ?array;
```

#### `Ritmo\Domain\Pricing\Ladder\PremiumRule` (final class)

```php
public readonly string $room;
public readonly array $over;
public readonly float $rateWeekendOrHoliday;
public readonly float $rateOther;
public function __construct(string $room, array $over, float $rateWeekendOrHoliday, float $rateOther);
```

#### `Ritmo\Domain\Pricing\Ladder\ProportionalLadder` (final class)

```php
public function __construct(LadderStrategy $strategy, array $rooms, array $ownerFloors, PriceFactor $factors, HolidayCalendar $calendar, array $weekendDays, MarketTopTable $tops);
public static function proportional(float $current, float $target, int $units, int $soldBefore, int $soldAfter): float;
public function target(string $room, DateTimeImmutable $night): ?float;
public function ceiling(string $room, DateTimeImmutable $night): ?float;
public function ownerFloor(string $room, DateTimeImmutable $night): ?float;
public function fallbackStep(string $room, DateTimeImmutable $night, float $current, int $bookings, DateTimeImmutable $today): float;
public function plan(array $before, array $after, DateTimeImmutable $today, array $batch = []): array;
```

#### `Ritmo\Domain\Pricing\Ladder\ProportionalMove` (final class)

```php
const REASON = 'PROPORTIONAL_LADDER';
const REASON_FALLBACK = 'PROPORTIONAL_LADDER_FALLBACK';
public readonly DateTimeImmutable $night;
public readonly string $roomCode;
public readonly float $minPrice;
public readonly ?float $maxPrice;
public readonly float $priceBefore;
public readonly int $soldBefore;
public readonly int $soldAfter;
public readonly ?float $target;
public readonly string $strategyId;
public readonly ?string $authorityOrderId;
public function __construct(string $roomCode, DateTimeImmutable $night, float $minPrice, ?float $maxPrice, float $priceBefore, int $soldBefore, int $soldAfter, ?float $target, string $strategyId, ?string $authorityOrderId);
public function reason(): string;
public function toPlanItem(): PlanItem;
```

#### `Ritmo\Domain\Pricing\Ladder\ProportionalRule` (final class)

```php
public readonly float $fallbackStepPercent;
public readonly ?string $bandRoom;
public readonly ?string $bandReferenceRoom;
public readonly float $bandFactor;
public readonly array $bandCapRooms;
public readonly float $bandCapDiscount;
public readonly ?float $temporaryFloor;
public readonly ?DateTimeImmutable $temporaryFloorUntil;
public function __construct(float $fallbackStepPercent = 5.0, ?string $bandRoom = null, ?string $bandReferenceRoom = null, float $bandFactor = 0.9, array $bandCapRooms = [], float $bandCapDiscount = 0.1, ?float $temporaryFloor = null, ?DateTimeImmutable $temporaryFloorUntil = null);
```

#### `Ritmo\Domain\Pricing\Ladder\RulerNight` (final class)

```php
public readonly DateTimeImmutable $night;
public readonly string $type;
public readonly array $series;
public readonly array $publicFactors;
public readonly array $referenceSent;
public function __construct(DateTimeImmutable $night, string $type, array $series, array $publicFactors = [], array $referenceSent = []);
public function valueAt(string $series, float $percentile): ?float;
public function publicFactor(string $propertyId): ?float;
public function referenceSent(string $room): ?float;
public static function fromArray(string $night, array $row): self;
```

#### `Ritmo\Domain\Pricing\Ladder\RulerPoint` (final class)

```php
public readonly string $series;
public readonly float $percentile;
public readonly float $multiplier;
public function __construct(string $series, float $percentile, float $multiplier = 1.0);
```

#### `Ritmo\Domain\Pricing\Ladder\SalesLadder` (final class)

```php
public readonly LadderStrategy $strategy;
public function __construct(LadderStrategy $strategy, array $rooms, ?FloorRule $quality, PriceFactor $factors, HolidayCalendar $calendar);
public function room(string $code): RoomType;
public function roomCodes(): array;
public function ruleFor(string $room, string $nightType): ?LadderRule;
public function openingPublic(string $room, RulerNight $ruler): ?float;
public function ceilingPublic(string $room, RulerNight $ruler): ?float;
public function steps(int $sold, int $units): int;
public function dropApplies(int $daysOut, int $sold, int $units, LadderRule $rule): bool;
public function publicPrice(string $room, RulerNight $ruler, int $sold, int $units, int $daysOut, ?float $referencePublic = null): ?LadderQuote;
public function toSent(string $room, RulerNight $ruler, float $public, LadderRule $rule): float;
public function qualityFloorSent(string $room, DateTimeInterface $night, LadderRule $rule): ?float;
public function factor(string $room, RulerNight $ruler): float;
```

#### `Ritmo\Domain\Pricing\Ladder\WeekendFloorOverride` (final class)

```php
public function __construct(LadderStrategy $strategy, ?FloorRule $quality, PriceFactor $factors, HolidayCalendar $calendar);
public function applies(RoomType $room, DateTimeImmutable $night, OrderBook $orders): bool;
public function standardFloor(HardFloor $floor, RoomType $room, DateTimeImmutable $night, OrderBook $orders): HardFloor;
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
public readonly PaceTerms $terms;
public function __construct(Property $property, RoomType $room, DateTimeImmutable $night, float $currentPrice, int $sold, int $units, ?MarketAnchor $anchor, ?float $floorInForce = null, ?float $ceilingInForce = null, ?LedgerStep $lastStep = null, ?int $paceSold = null, ?int $paceUnits = null, PaceTerms $terms = \Ritmo\Domain\Pricing\PaceTerms::__set_state(array(
   'percentile' => NULL,
   'byOrder' => false,
   'rampTop' => NULL,
   'rampShare' => 0.0,
   'freeStep' => false,
   'fixedPrice' => NULL,
   'floor' => NULL,
   'ceilingCap' => NULL,
   'onlyDown' => false,
   'listingTop' => NULL,
   'compareApplied' => false,
   'lockCeiling' => false,
)));
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
public readonly ?float $stepPrice;
public function __construct(string $roomCode, DateTimeImmutable $night, int $daysOut, NightType $nightType, DecisionKind $kind, string $reasonCode, float $currentPrice, array $trace, ?MarketAnchor $anchor = null, ?PaceReading $reading = null, ?float $targetPercentile = null, ?float $targetPublic = null, ?float $targetSent = null, ?float $publicFactor = null, ?float $currentPercentile = null, ?float $proposedPrice = null, ?StepDirection $direction = null, ?float $minPrice = null, ?float $maxPrice = null, ?HardFloor $floor = null, bool $heldAtFloor = false, bool $weakAnchor = false, ?float $stepPrice = null);
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

#### `Ritmo\Domain\Pricing\PaceTerms` (final class)

```php
public readonly ?float $percentile;
public readonly bool $byOrder;
public readonly ?float $rampTop;
public readonly float $rampShare;
public readonly bool $freeStep;
public readonly ?float $fixedPrice;
public readonly ?HardFloor $floor;
public readonly ?float $ceilingCap;
public readonly bool $onlyDown;
public readonly ?float $listingTop;
public readonly bool $compareApplied;
public readonly bool $lockCeiling;
public function __construct(?float $percentile = null, bool $byOrder = false, ?float $rampTop = null, float $rampShare = 0.0, bool $freeStep = false, ?float $fixedPrice = null, ?HardFloor $floor = null, ?float $ceilingCap = null, bool $onlyDown = false, ?float $listingTop = null, bool $compareApplied = false, bool $lockCeiling = false);
public function percentileFor(float $statusPercentile): float;
```

#### `Ritmo\Domain\Pricing\Protection\FloorSource` (enum)

```php
case MarketPercentile = 'market_percentile';
case StandardFloor = 'standard_floor';
case ExtraFloor = 'extra_floor';
case LiveFloorKept = 'live_floor_kept';
```

#### `Ritmo\Domain\Pricing\Protection\LiveBounds` (final class)

```php
public static function floor(?PriceNight $night, ?DateOverride $override, float $base): ?float;
public static function ceiling(?PriceNight $night, ?DateOverride $override, float $base): ?float;
```

#### `Ritmo\Domain\Pricing\Protection\ProtectionConfig` (final class)

```php
const ROOT = 'soldout';
public readonly bool $enabled;
public readonly float $percentile;
public readonly bool $useTargetOrderFloor;
public readonly bool $useRelationBounds;
public readonly bool $keepLiveFloorOnRelease;
public readonly array $retiredFloorMarkers;
public readonly array $retiredFloorExemptions;
public readonly ?int $lightRunMaxDays;
public function __construct(bool $enabled = true, float $percentile = 90.0, bool $useTargetOrderFloor = true, bool $useRelationBounds = true, bool $keepLiveFloorOnRelease = true, array $retiredFloorMarkers = [], array $retiredFloorExemptions = [], ?int $lightRunMaxDays = null);
public static function fromArray(array $data): self;
public static function fields(): array;
```

#### `Ritmo\Domain\Pricing\Protection\ProtectionItem` (final class)

```php
const REASON = 'SOLD_OUT_PROTECTION';
public readonly DateTimeImmutable $night;
public readonly string $roomCode;
public readonly float $minPrice;
public readonly float $maxPrice;
public readonly float $priceBefore;
public readonly FloorSource $floorSource;
public readonly bool $ceilingReleased;
public readonly int $sold;
public readonly int $units;
public readonly float $percentile;
public readonly float $marketPublic;
public function __construct(string $roomCode, DateTimeImmutable $night, float $minPrice, float $maxPrice, float $priceBefore, FloorSource $floorSource, bool $ceilingReleased, int $sold, int $units, float $percentile, float $marketPublic);
public function toPlanItem(): PlanItem;
```

#### `Ritmo\Domain\Pricing\Protection\ProtectionOutcome` (final class)

```php
const DISABLED = 'PROTECTION_DISABLED';
const NO_ANCHOR = 'SOLD_OUT_NO_ANCHOR';
const UNKNOWN_LIVE_FLOOR = 'PROTECTION_UNKNOWN_LIVE_FLOOR';
const ALREADY_IN_PLACE = 'PROTECTION_ALREADY_IN_PLACE';
const ITEM = 'SOLD_OUT_PROTECTION_ITEM';
public readonly string $code;
public readonly ?ProtectionItem $item;
public static function item(ProtectionItem $item): self;
public static function none(string $code): self;
```

#### `Ritmo\Domain\Pricing\Protection\SoldOutProtection` (final class)

```php
public function __construct(ProtectionConfig $config, float $tolerance, float $moneySlack = 1.0, float $percentSlack = 0.01);
public function propose(RoomType $room, PriceNight $live, ?DateOverride $override, ?MarketAnchor $anchor, float $publicFactor, float $standardFloor, ?float $extraFloor = null, ?float $relationCeiling = null): ProtectionOutcome;
public function extraFloor(?float $relationFloor, ?OwnerOrder $targetOrder, string $room): ?float;
public function relationCeiling(?float $ceiling): ?float;
public function inLightRunReach(int $daysOut): bool;
```

#### `Ritmo\Domain\Pricing\RuleCode` (final class)

```php
const NIGHT = 'night';
const PAST_NIGHT = 'past_night';
const SOLD_OUT_PENDING_M2 = 'sold_out_pending_m2';
const NO_MARKET_ANCHOR = 'no_market_anchor';
const PRICE_WITHIN_RULE_IN_FORCE = 'price_within_rule_in_force';
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

#### `Ritmo\Domain\Pricing\RuleInForce` (final class)

```php
const MARGIN = 0.5;
public static function floor(?DateOverride $rule, float $base): ?float;
public static function ceiling(?DateOverride $rule, float $base): ?float;
public static function price(float $price, ?float $floor, ?float $ceiling): float;
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

#### `Ritmo\Domain\Plan\CalendarNight` (final class)

```php
public readonly ?float $price;
public readonly ?float $floor;
public readonly int $sold;
public readonly int $total;
public function __construct(?float $price, ?float $floor, int $sold, int $total);
public function free(): int;
```

#### `Ritmo\Domain\Plan\FreshCalendar` (final class)

```php
public function __construct(array $nights = [], array $refreshedAt = []);
public function night(string $roomCode, DateTimeInterface $night): ?CalendarNight;
public function ageHours(string $roomCode, DateTimeImmutable $now): ?float;
public function nights(): array;
```

#### `Ritmo\Domain\Plan\IssueSubject` (enum)

```php
case Item = 'item';
case Deletion = 'deletion';
case Listing = 'listing';
case Plan = 'plan';
```

#### `Ritmo\Domain\Plan\LastMinuteRule` (final class)

```php
public readonly bool $enabled;
public readonly array $rooms;
public readonly int $maxDaysOut;
public readonly float $maxDiscount;
public readonly float $maxSoldShare;
public readonly array $lastUnitOnlyRooms;
public function __construct(bool $enabled = false, array $rooms = [], int $maxDaysOut = 3, float $maxDiscount = 0.2, float $maxSoldShare = 0.7, array $lastUnitOnlyRooms = []);
public function coversRoom(string $roomCode): bool;
public static function read(ConfigReader $reader, array $data, string $path): self;
public static function fields(string $path, string $roomPattern): array;
```

#### `Ritmo\Domain\Plan\ListingChange` (final class)

```php
public readonly string $roomCode;
public readonly ListingField $field;
public readonly float $value;
public readonly string $reasonCode;
public readonly string $reasonText;
public readonly ?string $orderId;
public function __construct(string $roomCode, ListingField $field, float $value, string $reasonCode = '', string $reasonText = '', ?string $orderId = null);
public function toArray(): array;
```

#### `Ritmo\Domain\Plan\ListingField` (enum)

```php
case Base = 'base';
case Min = 'min';
case Max = 'max';
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

#### `Ritmo\Domain\Plan\OrphanClaim` (final class)

```php
public readonly OrphanClaimKind $kind;
public readonly ?OrphanProof $proof;
public function __construct(OrphanClaimKind $kind, ?OrphanProof $proof = null);
public function toArray(): array;
```

#### `Ritmo\Domain\Plan\OrphanClaimKind` (enum)

```php
case NextNight = 'next_night';
case ClosedDayArrival = 'closed_day_arrival';
case ClosedDayDeparture = 'closed_day_departure';
```

#### `Ritmo\Domain\Plan\Payload\CallKind` (enum)

```php
case Delete = 'delete';
case Write = 'write';
case Listing = 'listing';
```

#### `Ritmo\Domain\Plan\Payload\ListingWrite` (final class)

```php
public readonly string $roomCode;
public readonly string $listingRef;
public readonly string $pms;
public readonly array $fields;
public function __construct(string $roomCode, string $listingRef, string $pms, array $fields);
public function toArray(): array;
```

#### `Ritmo\Domain\Plan\Payload\OverrideWrite` (final class)

```php
const PERCENT_OF_BASE = 'percent_base';
public readonly DateTimeImmutable $date;
public readonly ?string $reason;
public readonly ?int $minPercent;
public readonly ?int $maxPercent;
public readonly ?int $minStay;
public readonly ?string $checkIn;
public readonly ?string $checkOut;
public readonly string $currency;
public function __construct(DateTimeImmutable $date, ?string $reason, ?int $minPercent, ?int $maxPercent, ?int $minStay, ?string $checkIn, ?string $checkOut, string $currency);
public function toArray(): array;
```

#### `Ritmo\Domain\Plan\Payload\Payload` (final class)

```php
public readonly array $deletions;
public readonly array $writes;
public readonly array $listing;
public function __construct(array $deletions, array $writes, array $listing);
public function isEmpty(): bool;
public function toArray(): array;
```

#### `Ritmo\Domain\Plan\Payload\PayloadBuilder` (final class)

```php
public function __construct(string $pms, PlanConfig $config = \Ritmo\Domain\Plan\PlanConfig::__set_state(array(
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
     'maxDaysOut' => 3,
     'maxDiscount' => 0.2,
     'maxSoldShare' => 0.7,
     'lastUnitOnlyRooms' => 
    array (
    ),
  )),
   'reasonMaxLength' => 250,
   'sliceMaxDates' => 70,
)));
public function build(ValidatedPlan $plan, Property $property, array $rooms): Payload;
```

#### `Ritmo\Domain\Plan\Payload\RoomCall` (final class)

```php
public readonly array $entries;
public readonly CallKind $kind;
public readonly string $roomCode;
public readonly string $listingRef;
public readonly string $pms;
public function __construct(CallKind $kind, string $roomCode, string $listingRef, string $pms, array $entries);
public function dateOf(OverrideWrite|DateTimeImmutable $entry): DateTimeImmutable;
public function with(array $entries): self;
public function toArray(): array;
```

#### `Ritmo\Domain\Plan\Payload\Slice` (final class)

```php
const TONIGHT = 'D0';
const TOMORROW = 'D1';
const LISTING = 'listing';
public readonly int $number;
public readonly CallKind $kind;
public readonly string $label;
public readonly RoomCall|ListingWrite $request;
public function __construct(int $number, CallKind $kind, string $label, RoomCall|ListingWrite $request);
public function roomCode(): string;
public function first(): ?DateTimeImmutable;
public function last(): ?DateTimeImmutable;
public function dates(): array;
public function toArray(): array;
```

#### `Ritmo\Domain\Plan\Payload\Slicer` (final class)

```php
public function __construct(int $maxDates = 70);
public function slice(Payload $payload, DateTimeImmutable $today): array;
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
public readonly array $deletions;
public readonly array $listingChanges;
public readonly string $propertyId;
public function __construct(string $propertyId, DateTimeImmutable $today, DateTimeImmutable $createdAt, array $items, array $deletions = [], array $listingChanges = []);
public static function fromDecisions(Property $property, Clock $clock, array $decisions, array $before = []): self;
public function count(): int;
public function without(array $indexes, array $deletions = [], array $listingChanges = []): self;
```

#### `Ritmo\Domain\Plan\PlanConfig` (final class)

```php
const ROOT = 'plan';
public readonly float $calendarMaxAgeHours;
public readonly array $occupancyReasonCodes;
public readonly float $riseWarningPercent;
public readonly array $riseExemptReasonCodes;
public readonly int $maxMinStay;
public readonly int $marketEvidenceMinLength;
public readonly array $noDiscountRooms;
public readonly LastMinuteRule $lastMinute;
public readonly int $reasonMaxLength;
public readonly int $sliceMaxDates;
public function __construct(float $calendarMaxAgeHours = 6.0, array $occupancyReasonCodes = ['SOLD_OUT_PROTECTION', 'RELATION_DISCOUNT_BAND', 'RELATION_OCCUPANCY_SWITCH'], float $riseWarningPercent = 15.0, array $riseExemptReasonCodes = ['SOLD_OUT_PROTECTION'], int $maxMinStay = 30, int $marketEvidenceMinLength = 20, array $noDiscountRooms = [], LastMinuteRule $lastMinute = \Ritmo\Domain\Plan\LastMinuteRule::__set_state(array(
   'enabled' => false,
   'rooms' => 
  array (
  ),
   'maxDaysOut' => 3,
   'maxDiscount' => 0.2,
   'maxSoldShare' => 0.7,
   'lastUnitOnlyRooms' => 
  array (
  ),
)), int $reasonMaxLength = 250, int $sliceMaxDates = 70);
public static function fromArray(array $data): self;
public static function fields(): array;
public function dependsOnOccupancy(string $reasonCode): bool;
```

#### `Ritmo\Domain\Plan\PlanDeletion` (final class)

```php
public readonly DateTimeImmutable $date;
public readonly string $roomCode;
public readonly string $reasonCode;
public readonly string $reasonText;
public function __construct(string $roomCode, DateTimeImmutable $date, string $reasonCode = '', string $reasonText = '');
public function key(): string;
public function toArray(): array;
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
const STALE_CALENDAR = 'stale_calendar';
const STALE_CALENDAR_ACCEPTED = 'stale_calendar_accepted';
const RAISE_TODAY = 'raise_today';
const TODAY_WITHOUT_CURRENT_PRICE = 'today_without_current_price';
const BELOW_SAFETY_LIMIT = 'below_safety_limit';
const BELOW_LISTING_MIN = 'below_listing_min';
const BELOW_OWNER_FLOOR = 'below_owner_floor';
const BELOW_QUALITY_FLOOR = 'below_quality_floor';
const BELOW_HOLIDAY_FLOOR = 'below_holiday_floor';
const BELOW_HOLIDAY_SAFETY_FLOOR = 'below_holiday_safety_floor';
const BELOW_MARKET_FLOOR = 'below_market_floor';
const BELOW_FLOOR_BY_ORDER = 'below_floor_by_order';
const BELOW_FLOOR_BY_MARKET_FLOOR = 'below_floor_by_market_floor';
const BELOW_QUALITY_BY_ORDER = 'below_quality_by_order';
const BELOW_QUALITY_BY_MARKET = 'below_quality_by_market';
const ABOVE_LISTING_MAX = 'above_listing_max';
const CEILING_BELOW_FLOOR = 'ceiling_below_floor';
const NO_DISCOUNT_ROOM = 'no_discount_room';
const LAST_MINUTE_TOO_DEEP = 'last_minute_too_deep';
const LAST_MINUTE_WITHOUT_CALENDAR = 'last_minute_without_calendar';
const LAST_MINUTE_SOLD_OUT = 'last_minute_sold_out';
const LAST_MINUTE_TOO_SOLD = 'last_minute_too_sold';
const LAST_MINUTE_TOO_SOLD_TONIGHT = 'last_minute_too_sold_tonight';
const LAST_MINUTE_LAST_UNIT_ONLY = 'last_minute_last_unit_only';
const ORDER_NOT_IN_FORCE = 'order_not_in_force';
const ORDER_OUT_OF_SCOPE = 'order_out_of_scope';
const ORDER_DOES_NOT_RELEASE = 'order_does_not_release';
const NIGHT_FROZEN = 'night_frozen';
const STAY_BELOW_REQUIRED = 'stay_below_required';
const STAY_ABOVE_ONE_NIGHT = 'stay_above_one_night';
const STAY_ABOVE_REQUIRED = 'stay_above_required';
const STAY_OUT_OF_RANGE = 'stay_out_of_range';
const STAY_NOT_IN_ITEM = 'stay_not_in_item';
const ORPHAN_NOT_ALLOWED = 'orphan_not_allowed';
const ORPHAN_WITHOUT_PROOF = 'orphan_without_proof';
const ORPHAN_CONTRADICTED = 'orphan_contradicted';
const MASK_ALL_CLOSED = 'mask_all_closed';
const MASK_OPENS_CLOSED_DAY = 'mask_opens_closed_day';
const MASK_BEYOND_ORPHAN_SIDE = 'mask_beyond_orphan_side';
const MASK_CLOSES_OPEN_NIGHT = 'mask_closes_open_night';
const MASK_CLOSES_OPEN_DAY = 'mask_closes_open_day';
const KEEP_REASON_WITH_PRICE = 'keep_reason_with_price';
const BAND_ABOVE_REFERENCE = 'band_above_reference';
const BAND_CEILING_ABOVE_REFERENCE = 'band_ceiling_above_reference';
const BAND_ABOVE_UPPER = 'band_above_upper';
const BAND_BELOW_LOWER = 'band_below_lower';
const BAND_BELOW_BY_SWITCH = 'band_below_by_switch';
const BAND_BELOW_BY_ORDER = 'band_below_by_order';
const BAND_BELOW_CONFIRM_AFTER_RECALC = 'band_below_confirm_after_recalc';
const DELETE_REMOVES_STAY = 'delete_removes_stay';
const DELETE_REMOVES_HOLIDAY_FLOOR = 'delete_removes_holiday_floor';
const LISTING_NEEDS_ORDER = 'listing_needs_order';
const LISTING_WEEKLY_CHANGE_ABOVE_LIMIT = 'listing_weekly_change_above_limit';
const LISTING_BASE_BELOW_MIN = 'listing_base_below_min';
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
public readonly IssueSubject $subject;
public function __construct(string $severity, string $code, string $message, ?int $itemIndex = null, ?string $roomCode = null, ?DateTimeImmutable $date = null, array $params = [], IssueSubject $subject = IssueSubject::Item);
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
public readonly ?string $checkInMask;
public readonly ?string $checkOutMask;
public readonly ?string $orderId;
public readonly ?OrphanClaim $orphan;
public readonly ?string $marketEvidence;
public readonly bool $keepReason;
public readonly string $reasonText;
public readonly bool $readsOccupancy;
public function __construct(string $roomCode, DateTimeImmutable $date, ?float $minPrice = null, ?float $maxPrice = null, ?int $minStay = null, string $reasonCode = '', array $reasonParams = [], ?NightState $before = null, ?string $checkInMask = null, ?string $checkOutMask = null, ?string $orderId = null, ?OrphanClaim $orphan = null, ?string $marketEvidence = null, bool $keepReason = false, string $reasonText = '', bool $readsOccupancy = false);
public static function fromDecision(PaceDecision $decision, ?NightState $before = null): self;
public function setsDays(): bool;
public function setsPrice(): bool;
public function key(): string;
public static function keyOf(string $roomCode, DateTimeInterface $night): string;
public function toArray(): array;
```

#### `Ritmo\Domain\Plan\Port\NoStrategy` (final class implements StrategyGuard)

```php
public function backsStrategy(string $orderId): bool;
public function actsOn(string $orderId, string $roomCode, DateTimeImmutable $night): bool;
public function marketFloor(string $orderId, string $roomCode, DateTimeImmutable $night): ?float;
public function replacesOwnerFloors(string $roomCode, DateTimeImmutable $night): bool;
```

#### `Ritmo\Domain\Plan\Port\StrategyGuard` (interface)

```php
public function backsStrategy(string $orderId): bool;
public function actsOn(string $orderId, string $roomCode, DateTimeImmutable $night): bool;
public function marketFloor(string $orderId, string $roomCode, DateTimeImmutable $night): ?float;
public function replacesOwnerFloors(string $roomCode, DateTimeImmutable $night): bool;
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

#### `Ritmo\Domain\Plan\Validation\AuthorityCheck` (final class)

```php
public function __construct(OrderBook $orders, StrategyGuard $strategies, IssueLog $log);
public function resolve(int $index, string $room, DateTimeImmutable $night, ?string $orderId, IssueSubject $subject = IssueSubject::Item): ItemAuthority;
```

#### `Ritmo\Domain\Plan\Validation\BandCheck` (final class)

```php
public function __construct(DiscountBandCheck $bands, ?RelationReport $report, ?FreshCalendar $calendar, IssueLog $log);
public function check(array $facts): void;
```

#### `Ritmo\Domain\Plan\Validation\DeletionCheck` (final class)

```php
public function __construct(HolidayCalendar $calendar, ?StayRules $stay, IssueLog $log);
public function check(array $deletions, array $rooms, DateTimeImmutable $today): void;
```

#### `Ritmo\Domain\Plan\Validation\EffectCheck` (final class implements ItemCheck)

```php
public function __construct(PlanConfig $config, ?FreshCalendar $calendar, bool $acceptStaleCalendar, DateTimeImmutable $now, IssueLog $log);
public function check(ItemFacts $f): void;
```

#### `Ritmo\Domain\Plan\Validation\FloorCheck` (final class implements ItemCheck)

```php
public function __construct(RuleSet $rules, PriceFactor $factors, PlanConfig $config, StrategyGuard $strategies, LastMinuteCheck $lastMinute, IssueLog $log);
public function check(ItemFacts $f): void;
```

#### `Ritmo\Domain\Plan\Validation\IssueLog` (final class)

```php
public function item(ItemFacts $f, bool $error, string $code, string $message, array $params = []): void;
public function add(bool $error, string $code, string $message, ?int $index, ?string $room, ?DateTimeImmutable $date, array $params, IssueSubject $subject): void;
public function errors(): array;
public function warnings(): array;
```

#### `Ritmo\Domain\Plan\Validation\ItemAuthority` (final class)

```php
public readonly ?OwnerOrder $order;
public readonly bool $backsStrategy;
public function __construct(?OwnerOrder $order = null, bool $backsStrategy = false);
public function isValid(): bool;
public function id(): ?string;
public function allows(OrderRelease $release): bool;
```

#### `Ritmo\Domain\Plan\Validation\ItemCheck` (interface)

```php
public function check(ItemFacts $f): void;
```

#### `Ritmo\Domain\Plan\Validation\ItemFacts` (final class)

```php
public readonly PlanItem $item;
public readonly int $index;
public readonly PercentItem $percent;
public readonly RoomType $room;
public readonly int $daysOut;
public readonly NightType $nightType;
public readonly ?Holiday $holiday;
public readonly ItemAuthority $authority;
public readonly ?CalendarNight $fresh;
public readonly ?NightState $state;
public function __construct(int $index, PercentItem $percent, RoomType $room, int $daysOut, NightType $nightType, ?Holiday $holiday, ItemAuthority $authority, ?CalendarNight $fresh, ?NightState $state);
public function floor(): ?float;
public function lowest(): ?float;
public function current(): ?float;
public function floorInForce(): ?float;
```

#### `Ritmo\Domain\Plan\Validation\LastMinuteCheck` (final class)

```php
public function __construct(LastMinuteRule $rule, IssueLog $log);
public function check(ItemFacts $f, float $floor): void;
```

#### `Ritmo\Domain\Plan\Validation\ListingCheck` (final class)

```php
public function __construct(RuleSet $rules, AuthorityCheck $authority, IssueLog $log);
public function check(array $changes, array $rooms, array $reference, DateTimeImmutable $today): void;
```

#### `Ritmo\Domain\Plan\Validation\MaskCheck` (final class implements ItemCheck)

```php
public function __construct(?StayRules $stay, IssueLog $log);
public function check(ItemFacts $f): void;
```

#### `Ritmo\Domain\Plan\Validation\OrphanProofCheck` (final class)

```php
public function __construct(DateTimeImmutable $today, string $timezone, ?FreshCalendar $calendar);
public function failure(?OrphanProof $proof, string $neighbour, DateTimeImmutable $night, bool $closedDay): ?string;
public function contradicted(string $room, DateTimeImmutable $night, string $neighbour, bool $previousPast): bool;
```

#### `Ritmo\Domain\Plan\Validation\PriceBoundsCheck` (final class implements ItemCheck)

```php
public function __construct(RuleSet $rules, PlanConfig $config, IssueLog $log);
public function check(ItemFacts $f): void;
```

#### `Ritmo\Domain\Plan\Validation\StayCheck` (final class implements ItemCheck)

```php
public function __construct(?StayRules $stay, PlanConfig $config, OrphanProofCheck $proofs, IssueLog $log);
public function check(ItemFacts $f): void;
```

#### `Ritmo\Domain\Plan\ValidationContext` (final class)

```php
public readonly ?FreshCalendar $calendar;
public readonly OrderBook $orders;
public readonly StrategyGuard $strategies;
public readonly ?StayRules $stay;
public readonly ?DiscountBandCheck $bands;
public readonly ?RelationReport $relationReport;
public readonly array $weeklyReference;
public readonly bool $acceptStaleCalendar;
public function __construct(?FreshCalendar $calendar = null, OrderBook $orders = \Ritmo\Domain\Orders\OrderBook::__set_state(array(
   'orders' => 
  array (
  ),
   'defaultPercentile' => 50.0,
)), StrategyGuard $strategies = \Ritmo\Domain\Plan\Port\NoStrategy::__set_state(array(
)), ?StayRules $stay = null, ?DiscountBandCheck $bands = null, ?RelationReport $relationReport = null, array $weeklyReference = [], bool $acceptStaleCalendar = false);
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
public function rejected(IssueSubject $subject): array;
```

#### `Ritmo\Domain\Plan\Validator` (final class)

```php
public function __construct(RuleSet $rules, Clock $clock, ?PlanConfig $config = null);
public function validate(Plan $plan, Property $property, array $rooms, ?ValidationContext $context = null): ValidationResult;
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
