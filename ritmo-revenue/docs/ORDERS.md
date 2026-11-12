# Ritmo Revenue: owner orders (M2)

`src/Domain/Orders` holds the literal orders of the owner and the questions the engine and the validator ask them.
Pure PHP; orders are data (table `wp_ritmo_orders` from M3 on), the engine never writes them.

Reference engine: `ordens.py` (`cobre`, `ordem_valida`, `congelada`, `ordens_por_data`, `piso_da_ordem`,
`alvo_da_ordem`, `vendidas_base_escada`, `registrar_manual`), `pace.sem_alvo` and the owner exception of
`validar_plano.py`.

## 1. Orders

`OwnerOrder` (immutable): id, `OrderType`, rooms (codes or `*`), first and last night, optional list of nights,
nights moved to a newer order (for every room or per room), date given, creation instant (UTC), `OrderRelease`s,
floors per room (sent plan), percentile, units already sold per room and night, a note, and the `TargetTerms` the pace
step reads (sales ramp, free step, only down, strong-night thresholds, top, fallback floor, gradual descent).

| Type | Effect |
|---|---|
| `fixed_price` | The owner fixed the price: the engine never touches the night. |
| `manual` | A change made by hand in the pricing system: the night is frozen for `orders.manual_freeze_days` (7). |
| `floor` | Its floor replaces the standard floors (never below the safety limit); the engine keeps pricing. |
| `target` | A market percentile and a floor for weak nights; the engine keeps pricing. |
| `rule` | A standing rule; authorises what it releases. |
| `follow_up` | Authorises nothing. |
| `restriction` | A stay or arrival restriction. |

`OrderReader::read()` builds an order from a stored row and reports every problem of the row at once
(`InvalidConfig`). Keys are listed in its docblock.

- `covers(room, night)`: room listed (or `*`), night within the range and not moved to a newer order. The nights
  list is not part of coverage.
- `isInForceFor(room, night)`: covers it, lists the night when it lists nights, not a follow-up.

## 2. The order book and its precedence

`OrderBook` keeps the orders in the order they were recorded (that order breaks ties) and answers:

| Method | Answer | Reference |
|---|---|---|
| `gateFor(room, night)` | `OrderGate` `MANUAL_FREEZE` (first) or `ORDER_FIXED_PRICE` (with every order id), or null | `pace._plano` gates |
| `manualFreeze` / `fixedPrice` | the freezing manual order / the fixed-price orders covering the night | `congelada`, `ordens_por_data` |
| `inForce(id, room, night)` | the order when it may authorise something there | `ordem_valida` |
| `floorFor(room, night)` | the lowest floor of the floor and target orders that cover and list the night | `piso_da_ordem` |
| `targetFor(room, night)` | the target order with the lowest percentile (default `orders.defaults.target_percentile`) | `alvo_da_ordem` |
| `baseSold(room, night)` | units sold when the first covering order recorded them | `vendidas_base_escada` |
| `withoutTargets()` | the book without target orders (strong nights) | `pace.sem_alvo` |
| `with(order)` | the book with the order appended, replacing one with the same id | `registrar_manual` |

Precedence the engine applies to a room night: a night blocked without a booking, then a manual freeze, then a fixed
price take the night out of the engine and its live price stands (and is what related rooms read). Only then a sales
ladder that governs the night, the sold-out protection, and the pace step, where a floor or target order replaces the
standard floors.

`ManualFreeze::record()` turns a manual change into its order: id `manual-<today>-<room in lower case>-<first night>`,
from the first night to the last one or today plus the freeze days, whichever comes first. A change that would start
after the freeze ends is refused (the reference engine writes an order that covers nothing).

## 3. Exceptions need an order in force, in its scope

`OrderAuthority::check(orderId, room, night, needs, scope)` returns why an item is refused, or null:

1. `NIGHT_FROZEN_BY_OTHER_ORDER`: a manual change other than the cited order freezes the night (whatever the item needs);
2. `ORDER_ID_MISSING`: the item needs an exception and cites no order;
3. `ORDER_NOT_IN_FORCE`: unknown order, not covering the room or night, not listing it, or a follow-up;
4. `ORDER_DOES_NOT_RELEASE`: the order does not release every exception the item needs (`OrderRelease`);
5. `ORDER_OUT_OF_SCOPE`: the scope closure refuses it (for instance an order backing a ladder strategy counts only
   where the strategy acts).

Divergence (generalisation): the reference engine accepts any order in force except two families (stay rules, and the
owner's plan outside its scope); the plugin accepts an order only for what its releases list.

## 4. Configuration

`OrdersConfig` (`orders` section): `manual_freeze_days` (1–60, default 7), `defaults.target_percentile` (50),
`defaults.strong_night_occupancy` (0.7), `defaults.strong_night_ahead_occupancy` (0.5),
`defaults.fallback_trigger_days` (7), `defaults.descent_marks` ([7, 5, 3, 1]), `descent_reached_slack` (0.02). Fields
are described with `Domain\Config\Field` (`OrdersConfig::fields()`).

## 5. Parity

Goldens `tests/fixtures/golden/m2/ladder/ordens.*.json` and `pace.ordem_nova_sem_passo.json` (see LADDER.md, section
5), tests in `tests/Unit/Domain/Orders`. The golden inputs use the reference engine's order keys; the test helper maps
them to the plugin's (`order_keys`, `order_type` and `release` in the manifest vocabulary).
