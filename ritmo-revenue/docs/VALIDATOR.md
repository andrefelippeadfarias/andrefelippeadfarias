# Ritmo Revenue: plan validator, payload and slices (M2)

This document covers `src/Domain/Plan`: the plan (items, deletions, listing changes), the validator that is the only
way to a `ValidatedPlan`, the payload of a validated plan and the slices it is written in. Pure PHP, read-only for
external systems: the payload and the slices are data for the writer of milestone M3; nothing here holds a connector,
a `WriteToken` or an HTTP client.

Reference engine: `validar_plano.py` (`Validador`: `validar`, `validar_data`, `validar_apagar`, `validar_anuncio`,
`validar_balcony`, `validar_chegada_saida`, `padrao_qualidade`, `piso_vitrine_semana`, `calendario_fresco`,
`libera_estadia`, `checar_prova_orfa`; `precos_da_coleta`, `montar_payload`) and `fatiar_payload.py` (`fatiar`).

## 1. The plan

| Class | What it is |
|---|---|
| `Plan` | `propertyId`, `today`, `createdAt`, `items`, `deletions`, `listingChanges`. `without($items, $deletions, $listing)` drops entries by position. |
| `PlanItem` | One room night: `minPrice`, `maxPrice` (sent plan, currency), `minStay`, `checkInMask` / `checkOutMask` (seven `0`/`1` from Monday), `reasonCode`, `reasonParams`, `reasonText` (what the pricing system shows; empty: the code), `before` (`NightState`), `orderId` (the owner order it cites), `orphan` (`OrphanClaim`), `marketEvidence`, `keepReason`, `readsOccupancy`. At least a floor, a ceiling, a stay or a mask; the constructor checks the shape only. |
| `OrphanClaim` | `kind` (`OrphanClaimKind`: `next_night`, `closed_day_arrival`, `closed_day_departure`) and the `OrphanProof` read with the plan (`Calendar\Stay`). |
| `PlanDeletion` | A date rule deleted before the items are written (freeing a ceiling means deleting and writing again). |
| `ListingChange` | `field` (`ListingField`: `base`, `min`, `max`), `value`, `orderId`. Nothing else of a listing can be expressed. |

There is no price override in a plan item: floors and ceilings are always whole percentages over the base, so a fixed
price type cannot be written at all (the reference refuses `price_type: fixed` without an owner order; the plugin's
engine never asks for one).

## 2. Validating

```php
$validator = new Validator($rules, $clock, PlanConfig::fromArray($rules->get('plan')));   // config optional: neutral defaults
$result = $validator->validate($plan, $property, $rooms, new ValidationContext(
    $calendar,          // FreshCalendar: price, floor in force, sold/total per room night; recalculation instant per room
    $orders,            // Orders\OrderBook
    $strategies,        // Port\StrategyGuard (Port\NoStrategy without a sales ladder)
    $stayRules,         // Calendar\Stay\StayRules (null: only the stay range is checked)
    $bands,             // Relations\Validate\DiscountBandCheck (null: no band rule)
    $relationReport,    // the run's Relations\Coherence\RelationReport (accepts switch_satisfied)
    $weeklyReference,   // room => ['base' => .., 'min' => ..] at the start of the week
    $acceptStaleCalendar,
));
if (!$result->isValid()) {
    $plan = $plan->without($result->rejectedItems(), $result->rejected(IssueSubject::Deletion), $result->rejected(IssueSubject::Listing));
}
```

"Today" is the clock's, in the property's timezone, at the moment of validating: a plan computed for another day is
refused as a whole (`stale_plan`). `ValidatedPlan` keeps its private constructor and the WeakMap seal of M1.

What is in force for an item is its own `before`, else the night of the fresh calendar (price and floor); the current
price and the floor in force fall back to the calendar the same way.

### 2.1 The rules

Every issue is a `PlanIssue` (`severity`, `code`, `message`, `itemIndex`, `roomCode`, `date`, `params`, `subject`).
`E` = error (stops the plan), `W` = warning.

| Code | | Rule | Reference |
|---|---|---|---|
| `stale_plan` | E | The plan is of another day. | `main` (`plano de outro dia`) |
| `unknown_room`, `duplicate_item`, `missing_reason`, `past_date` | E | Shape, per list (items, deletions, listing changes). A deletion and an item of the same night are the normal delete-then-write group. | `_validar`, `validar_apagar` |
| `night_frozen` | E | A manual change of the owner freezes the night (unless the item cites that very order). | `congelada` |
| `order_not_in_force` | E | The cited order does not cover the room night (or lists other nights, or is a follow-up). | `ordem_valida` |
| `order_out_of_scope` | E | The cited order backs a strategy that does not act on the room night (`StrategyGuard::actsOn`). | `escopo_plano` |
| `order_does_not_release` | E | A listing change whose order does not release `listing`. | `validar_anuncio` |
| `raise_today` / `today_without_current_price` | E | Tonight never rises, also through the rounding of the floor (M1). | M1 rule |
| `below_safety_limit` | E | The floor (raw or applied), or the ceiling of a ceiling-only item, under the fraction of the reference minimum; never released; when it fails no other floor is reported. | `limite de segurança` |
| `below_listing_min` | E | The applied floor under the listing minimum, without an order that allows it and outside the last-minute rule. | Regra I |
| `last_minute_too_deep`, `_without_calendar`, `_sold_out`, `_too_sold`, `_last_unit_only` (E), `_too_sold_tonight` (W) | | The last-minute discount (`plan.validator.last_minute`): rooms listed, at most N days out, not deeper than the share, with the fresh calendar, units free, not mostly sold, last unit only in the rooms listed. | Regra I |
| `below_holiday_safety_floor` | E | On a holiday-priced night, the floor in the holiday floor's plan under the general holiday floor; never released. | `piso_feriado_real_seguranca` |
| `below_holiday_floor` | E | The period's own floor (one unit of slack, in its plan) without an order that allows it. | `piso_real` |
| `below_owner_floor` | E | An owner floor of the night type (one unit of slack), unless an order allows it or a strategy replaces the floors that night (`replacesOwnerFloors`). | `piso_vitrine_semana` |
| `below_floor_by_order` | W | Same, allowed by the cited order (`below_standard_floor`). | `por ordem do dono` |
| `below_market_floor` / `below_floor_by_market_floor` | E / W | Same, cited order behind a strategy: down to its last-minute market floor less 1% of the base and one unit; without a market floor that night, refused. | `piso_mercado_do_plano` |
| `below_quality_floor` | E | The quality floor (one unit of slack). | `padrao_qualidade` |
| `below_quality_by_order`, `below_quality_by_market` | W | Same, allowed by an order, or by market evidence in the item (at least N characters with a number). | `campo mercado` |
| `above_listing_max` | E | The applied floor, or the ceiling, over the listing maximum (+0.5). | `piso efetivo acima do máximo` |
| `ceiling_below_floor` | E | A ceiling under the item's floor, or (no floor) under the floor in force by more than one unit. | `max_price abaixo do piso`, `piso vigente` |
| `no_discount_room` | E | A room of `no_discount_rooms` under its current price (floor or ceiling), or without the price. | `sem_desconto` |
| `keep_reason_with_price` | E | `keepReason` on an item that changes a floor or a ceiling. | `manter_reason` |
| `stale_calendar` / `stale_calendar_accepted` | E / W | A floor that reads occupancy (`readsOccupancy`, a reason code of `occupancy_reason_codes`, or under the listing minimum) with a calendar older than `calendar_max_age_hours`; accepted explicitly, a warning. | `calendario_fresco`, `--aceitar-velho` |
| `stay_below_required` | E | Under the stay a holiday, a package, the arrival rule or a closed day requires, unless a proven orphan or an order that releases `stay`, given since the arrival rule began. | `exige estadia mínima`, `sábado com 1 noite` |
| `stay_above_one_night` / `stay_above_required` | E / W | Above the arrival rule's stay where it is one night / longer. | `abre 1 diária`, `acima da do dia` |
| `stay_out_of_range` | E | Above `max_min_stay`. | `fora do razoável` |
| `stay_not_in_item` | W | A floor on a closed day or holiday night without its stay. | `sem min_stay no item` |
| `orphan_not_allowed`, `orphan_without_proof`, `orphan_contradicted` | E | An orphan night outside where the rule allows it; without its proof (inventory read today or yesterday, or calendar units); contradicted by the fresh calendar. | `checar_prova_orfa` |
| `mask_all_closed`, `mask_opens_closed_day`, `mask_beyond_orphan_side`, `mask_closes_open_night`, `mask_closes_open_day` | E | Arrival and departure days (section 2.2). | `validar_chegada_saida` |
| `band_*` | E/W | The discount bands of the relations, in currency after rounding (`Relations\Validate\DiscountBandCheck`; RELATIONS.md, section 8), reported on the item of the room night or on the plan. | `validar_balcony` |
| `delete_removes_stay`, `delete_removes_holiday_floor` | W | A deletion drops the stay of a closed day, holiday or night that requires more than one night (not a held night), or a holiday's floor. | `validar_apagar` |
| `listing_needs_order`, `listing_weekly_change_above_limit`, `listing_base_below_min` (and `below_safety_limit` for a minimum) | E | Listing changes only by order, within the weekly limit of the rule set against the week's reference. | `validar_anuncio` |
| `no_effect` | E | Everything the item sets is already in force (M1). | `pace.sem_efeito` |
| `rise_above_max_step` | W | The applied floor more than `rise_warning_percent` (+1) above the current price, outside holidays, unless the order allows a larger rise or the reason code is exempt. | `mais de 15% acima` |
| `no_before` | W | Nothing known in force for the night (M1). | |

### 2.2 Arrival and departure days

A mask the item leaves out is written all open. The character of the night's weekday counts. On a closed day
(`stay.closed_days`, or the restriction of its open date), opening a side the rule closes needs an order that releases
`arrival_departure`, or one that releases `orphan_stay` with the matching orphan claim (`closed_day_arrival` opens
the arrival only, `closed_day_departure` the departure only). On any other night, closing it needs an order that
releases `arrival_departure`; with a cited order that does not, an enforced open day (`stay.open_days`) is reported as
`mask_closes_open_day`.

### 2.3 Orders and strategies

An order counts when it is in force for the room night and, when it backs a strategy, inside the strategy's scope.
It allows what its releases list (`Orders\OrderRelease`); an order behind a strategy also allows what a strategy
decides: floors under the standard ones (down to its market floor where the owner floors are concerned) and a larger
rise. `Port\StrategyGuard` is the question the validator asks the sales ladder; `Port\NoStrategy` answers for a site
without one.

## 3. Configuration (`plan`)

`PlanConfig::fromArray($section)` fills the neutral defaults and throws `InvalidConfig` with every error;
`PlanConfig::fields()` describes each field (type, range, default, label, help) for the rule set schema.

| Key | Default | Parity |
|---|---|---|
| `validator.calendar_max_age_hours` | 6 | 6 |
| `validator.occupancy_reason_codes` | `SOLD_OUT_PROTECTION`, `RELATION_DISCOUNT_BAND`, `RELATION_OCCUPANCY_SWITCH` | none (the items carry `readsOccupancy`) |
| `validator.rise_warning_percent`, `rise_exempt_reason_codes` | 15, `SOLD_OUT_PROTECTION` | the same |
| `validator.max_min_stay` | 30 | 7 |
| `validator.market_evidence_min_length` | 20 | 20 |
| `validator.no_discount_rooms` | none | none |
| `validator.last_minute.{enabled, rooms, max_days_out, max_discount, max_sold_share, last_unit_only_rooms}` | off, none, 3, 0.2, 0.7, none | on, the rooms of Regra I, 3, 0.2, 0.7, the rooms in high demand |
| `payload.reason_max_length` | 250 (at most 255) | 250 |
| `payload.slice_max_dates` | 70 | 70 |

## 4. Payload and slices

`Payload\PayloadBuilder($pmsName, $config)->build($validatedPlan, $property, $rooms)` converts the items with
`PercentRounding::apply()` (refuses an unsealed plan or a changed base; drops items whose percentages are in force) and
returns `Payload`: per listing in plan order, the deletions (`RoomCall` of dates), the writes (`RoomCall` of
`OverrideWrite`: `date`, `reason` cut to the configured length in characters or null to keep the rule's, floor and
ceiling as `percent_base` with the property's currency, `min_stay`, both masks with `check_in_check_out_enabled`)
and the listing changes (`ListingWrite`). `toArray()` gives the pricing system's fields, every value a string.

`Payload\Slicer($maxDates)->slice($payload, $today)` lists the calls in writing order: every deletion first; then
tonight of every listing, tomorrow of every listing, then the rest listing by listing in chunks labelled `k/n`; the
listing changes last. A call never repeats a date. `Slice` gives `number`, `kind` (`CallKind`), `label`, `first()`,
`last()`, `dates()` and the `request` to send.

## 5. Parity

`python3 tests/fixtures/tools/golden_plan.py` (with `freeze --source NAME=DIR` to copy the inputs again, read only)
writes `tests/fixtures/golden/m2/plan/`: `validar_plano.validar.json` (11 plans), `validar_plano.montar_payload.json`
and `fatiar_payload.fatiar.json` (the 4 plans that pass), `MANIFEST.json` (engine commit, `engine_dirty`, hashes of the
frozen inputs, vocabulary, divergences) and `tests/fixtures/presets/parity-plan.json`.

Frozen inputs (`golden/m2/plan/frozen/`): the real plans of 11 and 12 November (final plan of both days and the
delete-first plan of the 12th), the pricing calendars and PMS inventories of those days reduced to the room nights the
plans touch, the owner orders without their texts, and synthetic plans for floors, stays and masks, orders and listing
changes, deletions, occupancy (fresh, stale and accepted) and a payload of 156 dates. The engine's messages are mapped
to issue codes by markers (`MESSAGES`); the answers of the owner's strategy (scope, market floor, weekend floors) and
the band releases are recorded per case with the engine's own helpers.

`tests/Unit/Domain/Plan/Golden/PlanGoldenTest` validates each plan per property and compares, entry by entry, the set
of (severity, code); builds and slices the payload per property and compares it with the reference's calls of that
property, in order. The proofs of the real orphan nights are checked against the frozen inventory.

## 6. Where the plugin decides differently

In the goldens (applied to the expected codes, listed per case in `divergences`):

| # | The plugin | The reference |
|---|---|---|
| V1 | Under the safety limit, only that floor is reported (M1). | Also the listing minimum, owner floors, Regra I... |
| V3 | `no_before` warning when nothing is known in force for the night (M1). | Silent. |
| V4 | Tonight never rises, also through the rounding of the floor: the real plan of 12 Nov had a protection floor of 1034 for tonight sent as -18% (1041.40) over a price of 1041; the plugin refuses that item and writes the rest. | Its validator does not look at tonight. |
| V5 | An order silences the rise warning only when it releases a larger rise or backs the strategy. | Any order in force silences it. |

Not in the goldens (by design):

- An order allows only what it releases (ORDERS.md): under the listing minimum, a holiday period floor or the quality
  floor needs `below_standard_floor` (or a strategy order); a stay, `stay`; closing a night, `arrival_departure`; a
  listing change, `listing` and an order in force. The reference accepts any order in force there (and does not check
  the order of a listing change at all).
- An orphan stay is always checked against its proof and the fresh calendar; the reference accepts the closed day's
  orphan order cited as owner exception without a proof outside holidays.
- The quality floor at zero switches the rule off (neutral default); the reference refuses every plan without it.
- The general holiday floor applies on holiday-priced nights (M1 `Floors`), the period's own floor on every night of
  the period; in the parity preset every period is priced as holiday.
- A ceiling over the listing maximum is refused (M1); the reference checks only the floor.
- Not ported: customisation items of the pricing system (OTA discounts, offers and programmes are not expressible in a
  plan), the old Saturday rule of 1 Nov (`excecao: regra_h`, before the arrival rule) and the register of market
  exceptions with review dates (`excecoes_mercado`, M4 market module); the stay-rule orders of the reference are
  configuration in the plugin.

## 7. Integration (done in M2)

- Schema: `PlanConfig::fields()` is the section `plan` of the rule set; `parity-plan.json` is merged into `parity.json`
  (`weekly_reference` and `pms` are inputs of the run and of the pricing connector).
- The run's `ValidationContext` is built by `Plan\Assembly\RunWiring::validationContext()`: the fresh calendar of the
  run, its order book, `Assembly\Hooks\LadderStrategyGuard` (the strategy's scope: the nights it governs, its
  last-minute market floor, the nights where an occupancy switch citing its order held), the stay rules, the discount
  bands with the run's `RelationReport`. Plan items come from the assembly (masks, order, orphan claim,
  `readsOccupancy`); the ceiling release fills `Plan::$deletions`. See ENGINE.md, section 1.8.
- The validator never calls `no_effect` an item whose night the plan deletes first, and `PercentRounding` never drops
  it (it writes that rule again). When the plan is validated again without what was refused, a deletion and the item
  that writes its night again go together.
- Not wired: `Relations\RoundingRecheck` (the order of related rooms checked again after the rounding to whole
  percentages). The reference does not check it either; the decision is left to the review of M2.
