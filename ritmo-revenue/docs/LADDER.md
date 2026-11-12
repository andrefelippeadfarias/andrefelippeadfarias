# Ritmo Revenue: sales ladder, sold-out protection and step cadence (M2)

This document covers three engine modules under `src/Domain/Pricing`:

- `Ladder/`: the sales ladder (stepped mode and the optional proportional mode), its last-minute market floor and
  its weekend floor override;
- `Protection/`: the protection of sold-out nights;
- `Cadence/`: one step per night per day, the step ledger and the no-effect filter.

The owner orders they read are in [ORDERS.md](ORDERS.md). All of it is pure PHP (no WordPress, clock only through
`Clock`, rounding only through `Num`) and read-only for external systems: these classes compute items for the plan;
nothing here writes to a PMS or to a pricing system.

Reference engine: `pace.py` (`plano_da_noite`, `item_do_plano`, `piso_duro_do_plano`, `piso_semana_mercado`,
`pisos_enviados`, `protecao_esgotada`, `cadencia_permite`, `ordem_nova_sem_passo`, `plano_no_ledger`,
`registrar_passos`, `marcar_envio`, `sem_efeito`), `plano_dono.py` and `escada_reservas.py`.

## 1. Sales ladder

A **strategy** (`LadderStrategy`, configured under `ladder.strategies`) covers a period of nights and prices some
rooms on some night types of the **market ruler**:

| Concept | Class | What it does |
|---|---|---|
| Market ruler | `MarketRuler`, `RulerNight` | Per night: a night-type label, public prices of each market series at stored percentiles, the public factor measured per property, the reference sent price of each room. Lookups are exact (no interpolation); a night without an entry is a ruler gap. |
| Rule | `LadderRule` (`RulerPoint`, `Derivation`) | Night types + rooms (or room tags). Opening point (series, percentile, multiplier, optional cap at the reference price), ceiling point, or a derivation from another room (its price times a multiplier). Quality floor and drop switches. First matching rule wins; a night type no rule lists is not priced. |
| Activation | `LadderActivation` | Enabled, night inside the period and, when the strategy names an owner order, that order in force for the room and night. Removing the order switches off everything the strategy does. |
| Ladder | `SalesLadder` | `openingPublic`, `ceilingPublic` (never below the opening; without a value, the opening), `steps(sold, units)` (none until `ceil(units × trigger_fraction)` units are sold, then one per unit; a sold-out room is priced as its last unit), `dropApplies` (`0 ≤ D ≤ window` and `sold / units < occupancy_below`, strict), `publicPrice` (opening × (1 + step%)^steps capped at the ceiling, then the drop), `toSent` (public ÷ the night's factor, never below the quality standard when the rule applies it). |
| Hard floor | `LadderHardFloor` | The safety limit and, on a configured holiday night, the general holiday floor in the sent plan. The listing minimum and the period's own higher floor do not apply on ladder nights. |
| Night | `LadderNightEvaluator` → `LadderNight` of `LadderRoomQuote` | Prices every room of the night at once: rooms with their own opening, then derived rooms (from their source's mounted public price). A blocked room, a room frozen by a manual change or fixed by an owner order keeps its live price, which the relations read. Nights already inside the drop window on the activation date skip the drop. Reports a ruler gap when the calendar classifier says the strategy would price the night. |
| Relations of the night | `LadderRelations`, `LadderReferences` | `between` (a room between the cheaper of two selling rooms and a position toward the dearer one) and `premium` (a derived room above the dearest selling room by the weekend/holiday or the other rate), each with its limits band. Applied again with the prices that really stay on the channel (`LadderReferences` with `reals`). |
| Item | `LadderItemBuilder` → `LadderOutcome` (`LadderItem`, `LadderLine`) | Caps at the listing top the caller gives (the chained listing maximum), never raises tonight, tests the direction on the effective value going up and the raw value going down, rounds up / down / half to even, never below the hard floor; no item when the live floor and ceiling are already at the price and the relation limits hold; light-run filter; cadence with its three ladder exceptions. Floor = ceiling when `lock_ceiling` is on. |
| Last-minute market floor | `LastMinuteMarketFloor` | On the strategy's weekday labels, inside the window, outside holidays and excluded weekdays: ruler value × room multiplier ÷ factor, never below the safety limit. The pace step uses it only when lower than its own floor. |
| Weekend floor override | `WeekendFloorOverride` | While the strategy acts on a weekend night outside holidays, the owner floors of the rooms the quality standard covers give way to the quality standard (`standardFloor()` rewrites an M1 `HardFloor`). |
| Effective value | `EffectiveValue` | What the pricing system applies for a floor (rounded up) or ceiling (rounded down) stored as a whole percentage of the base. |

Reason codes: `LADDER_PRICE`, then `RELATION_BETWEEN` / `RELATION_PREMIUM_OVER_MAX` when the relations moved the
price. Line holds: `LADDER_TODAY_NEVER_UP`, `OUT_OF_NIGHT_FILTER`, `CADENCE_NOT_ON_CHANNEL`, `CADENCE_WITHIN_24H`.
Items convert to the plan's `PlanItem` with `toPlanItem()` (reason codes, authority `{strategy, order}` and step in
`reasonParams`).

### Proportional mode (optional)

`ProportionalLadder` (strategy `mode = proportional`; off in the neutral and parity presets): between two calendar
readings, each new sale moves the price along the line from the price in force to a market target
(`MarketTopTable`: holiday periods, else the nearest weekday or weekend reference), `proportional()`; without a
target, a fixed step per booking up to the owner-floor ceiling (`fallbackStep()`); a cancellation moves it down, never
below the owner floor (or a temporary minimum until a date). A discount room follows a share of its reference room
and stays under the cheapest free cap room. `BatchImportDetector` keeps channel batch imports (bookings created in
the same minute, three or more) out of the sales count. The ordering relations that follow the moves belong to the
relations pass.

## 2. Sold-out protection

`SoldOutProtection::propose()` → `ProtectionOutcome` (`ProtectionItem` or a reason):

```
floor = min(max(market value at the percentile / property public factor, standard floor, extra floor), cap)
cap   = relation ceiling or listing maximum
```

- Only raises: no item when the live floor is within the tolerance of the floor and there is no ceiling to release
  (`PROTECTION_ALREADY_IN_PLACE`); no item while the live floor is unknown (`PROTECTION_UNKNOWN_LIVE_FLOOR`).
- Releasing a ceiling never lowers the floor (a fix of the reference engine): a live floor above the
  protection floor stays and only the ceiling goes (`FloorSource::LiveFloorKept`), unless no live override is known
  or its reason marks a retired rule (`retired_floor_markers` / `retired_floor_exemptions`).
- The ceiling is the listing maximum (free: the plan deletes the override first) or the relation ceiling rounded
  down; with a relation ceiling within one rounding step of the live one, nothing to write.
- The reason names where the floor really comes from: `FloorSource` (market percentile, standard floor, extra floor,
  live floor kept).
- The standard floor never contains the selling floor of a dated order; the target order's floor enters as an extra
  floor (`extraFloor()`), the relation bounds through `relationCeiling()`, both behind their switches.
- Not a step: no cadence, no ledger.

`LiveBounds` gives the live floor and ceiling of a night: the calendar's value when above zero, else the live
override's (percentage of the base or fixed amount), else unknown.

## 3. Cadence

- `StepCadence::permits()` → `CadenceVerdict`: only the last step of the room night counts (latest instant; first
  recorded on a tie). Other direction at once; a booking (up) or cancellation (down) since that step, same base and
  same total, releases one more; otherwise the step must be on the channel and at least `min_hours_between_steps`
  old (exactly that is enough).
- `StepCadence::newOrderBypass()`: an owner order given today, with its creation instant, takes effect once if no
  step of the room night was written at or after that instant.
- `StepLedger` (immutable, rows `StepRecord`): `lastStep`, `hasLadderStep`, `record(proposals, now)` (only written
  steps with a direction, stamped to the second), `markOnChannel(pushedAt)` (one instant or one per room; a row
  stored to the minute counts only when its whole minute ended before the push), `pendingCount`.
- `StepProposal` is the step block an item carries; `StepRecord::LADDER` marks ladder steps.
- `NoEffectFilter` → `NoEffectResult`: drops items whose greatest effective value equals the live floor and ceiling
  (within the money slack), and, when switched on, items that are only a rounding step away (same whole percentage as
  the live floor and ceiling). Items with a minimum stay or on a night deleted first always stay.

## 4. Configuration

Each section describes its fields with `Domain\Config\Field` (type, range, neutral default, label, help) and reads
them with `ConfigReader` (defaults, unknown keys, ranges, every problem reported at once as `InvalidConfig`):

| Section | Class | Neutral default |
|---|---|---|
| `ladder` | `LadderConfig::fromArray()`, `LadderFields::all()` | off, no strategy |
| `soldout` | `ProtectionConfig` | protection on, p90, keep the live floor, no retired markers, no light-run limit |
| `cadence` | `CadenceConfig` | 24 h, wait for the channel, every exception on |
| `orders` | `OrdersConfig` (ORDERS.md) | 7 frozen days, percentile 50, an order applies to every night not sold out, no fallback before the night, no descent marks |

A new strategy's fields default to values that change nothing until they are set: steps only when every unit is sold
(`step.trigger_fraction` 1), a drop of 0 % over 0 days below 0 % sold (`drop` is also off), no weekend nights for the
floor override, a "between" room up to its dearer room (`position` 1), a premium of 0 over the dearest room, the
discount room at its reference (`band_factor` 1, `band_cap_discount` 0). The owner's values are in the parity
preset only.

The quality standard (`floors.quality_floor`), the holiday floor, the owner floors, the price factors, the weekend
days and the tolerance come from the M1 rule set.

## 5. Parity

- Fixture: `tests/fixtures/presets/parity-ladder.json`, generated by `tests/fixtures/tools/golden_ladder.py` from
  `plano_dono.py` (opening and ceiling points, 7 % steps after half the units, −10 % from D7 to D0 below 60 % sold,
  last-minute floor at the comparable median, derived premium room at 1.15) and the frozen inputs; it also carries the
  frozen market ruler in the plugin's format.
- Frozen inputs: `tests/fixtures/frozen/quartos.json` (M1) and `tests/fixtures/frozen/ladder/regua_dono.json`
  (`golden_ladder.py freeze`, numbers only).
- Goldens: `tests/fixtures/golden/m2/ladder/*.json` (31 files) with their `MANIFEST.json`; tests under
  `tests/Unit/Domain/Pricing/{Ladder,Protection,Cadence}`. Amounts within 0.01, counts, codes and booleans exact.
- Regenerate: `python3 tests/fixtures/tools/golden_ladder.py freeze` (only when the ruler changes), then
  `python3 tests/fixtures/tools/golden_ladder.py`.

### Where the PHP decides differently on purpose

| # | Reference engine | Plugin | Effect on parity |
|---|---|---|---|
| 1 | The protection percentile is always 90 (the configured value is never read). | `soldout.protection.percentile` is honoured. | none (parity 90) |
| 2 | The retired rule is recognised by the text "Regra J" in the override reason. | Configurable markers and exemptions. | none (parity sets them) |
| 3 | A ruler night without the property's public factor raises. | Falls back to the configured public factor. | none |
| 4 | An unknown ruler label counts as a weekday for the last-minute floor. | Only the labels listed in `last_minute_floor.night_types`. | none (all labels known) |
| 5 | The quality standard applies to every room except the discount room outside weekday nights. | Applies where a ladder rule says so, to rooms with the quality tags. | none (only priced rooms reach it) |
| 6 | The last step of a night is the greatest write time as text. | The greatest instant (UTC). | differs only with mixed offsets |
| 7 | Batch imports group the booking dates as text and count "booked" bookings. | Creation instants in UTC; confirmed and new bookings; bookings without a creation instant are ignored. | none on the goldens |
| 8 | The proportional ceiling and cancellation floor always use the suites' owner floors. | A room without an owner floor has no ceiling and no lower cancellation floor. | none |
| 9 | No rounding-only filter. | `NoEffectFilter` drops rounding-only items when switched on. | off in parity |
| 10 | The safety limit honours the owner's dated exceptions. | Not modelled yet (M1 `Floors::safetyLimit`). | goldens use `core_cfg` (no exceptions) |
| 11 | The safety limit is not rounded. | Two decimals (M1). | within 0.01 |

Kept on purpose (same as the reference): a derived room without its source room's price takes the drop with its own
counts while reporting no steps and no drop; the no-effect test compares the greatest effective value of an item with
floor below ceiling.
