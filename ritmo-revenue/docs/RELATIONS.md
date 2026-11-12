# Ritmo Revenue: relations between room types

`Ritmo\Domain\Relations` keeps the prices of the room types of a property in a commercial order ("the room
without a tub about 10% below the cheapest tub suite that still has units", "the top suite always the most
expensive"). The reference engine writes these rules against room names in four places; the plugin has **one
generic graph of relations**, configured per property. Pure PHP, no WordPress; time only through the `today`
given by the caller, rounding only through `Support\Num` (via `Relations\Grid`).

Milestone M2 ships: the model and its configuration, validation on save, the resolution order, the coherence
stage, the currency re-check after percent rounding, the audit, the listing-maximum chain, the bounds of the top
room and the validator rule of the bands. The strategy stage (relations inside a sales-ladder night) is computed
by the ladder (`Pricing\Ladder`), which reads the same `Relation` objects.

## 1. The five relation types

| Type | The target (A) ... | Params (`params.*`) |
|---|---|---|
| `at_least` | never below a reference B, plus a margin (percent or amount) | `reference`, `margin {type, value}`, `reference_min_units_left` (2 = the last unit of B does not pull A), `derive`, `derive_min` |
| `between` | between two rooms, from the cheaper one up to a point of the way to the dearer one (`mode: path`), or between two percents of one room (`mode: percent_of`) | `low_reference`, `high_reference`, `position_min`, `position_max` / `reference`, `min_percent`, `max_percent`; `references` (`free_only` or `all`) |
| `premium_over_max` | above the dearest room of a set by a percent per night type (+ `epsilon_amount`, strictly above) | `set`, `premium_percent {weekday, weekend, holiday}`, `epsilon_amount`, `members` |
| `discount_band` | between `lower_percent` and `upper_percent` of the cheapest **free** room of a set (85.5% to 90%) | `set`, `upper_percent`, `lower_percent`, `reference_rounding_margin`, `lower_extra_amount`, `members`, `on_empty_set` (`skip`, `use_sold_out`), `down_only_night_types`, `order_floor_yields`, `follow_strategy_reference`, `write_mode` (`pin`, `bounds`) |
| `occupancy_switch` | when X% (or N units) of a set are sold, changes rule: goes to a market price (`then.kind: market_percentile`) or keeps its price (`hold`); the relations in `suspends` do not run that night | `trigger {set, unit, threshold}`, `then {kind, by_night_group}`, `cap_relation` (a band whose upper edge still caps while a member is free), `floor: safety`, `suspends` |

Common fields of a relation (`relations.items.*`): `id`, `label`, `enabled`, `property`, `type`, `target`,
`priority`, `stages` (`strategy`, `coherence`, `audit`, `validate`), `scan` (`touched`: only nights the engine
changed; `horizon`: every night of the scan horizon), `strategy_nights` (`apply`, `skip`, `only`), `scope`
(`night_types`, `weekdays`, `periods`, `exclude_periods`, `min_days_out`, `max_days_out`, `requires_order`),
`order_ref` (owner order cited on the items it writes).

A **room set** is `{"rooms": [...]}` or `{"room_tags": [...]}`; with tags it is every room of the property that
has all the tags, in room order, the target excluded (`RoomSet::resolve`).

## 2. Configuration

```
{"relations": {"tolerance": {"trigger_amount", "audit_base_percent", "audit_amount"}, "scan_horizon_days",
               "audit_horizon_days", "blocked_nights", "rounding_recheck": {"mode", "epsilon_amount"}, "items": [...]},
 "price_bands": {"items": [{"id", "property", "room", "night_types", "price_plan", "min_amount", "max_amount",
                            "max_days_out", "skip_on_strategy_nights"}]}}
```

- `Config\RelationsConfig::fromArray()` reads and checks types and ranges (one `InvalidConfig` with every
  `ValidationError`, dotted path included). `RelationsConfig::neutral()` = no relation, no band.
- Neutral defaults: tolerance 1 / 1% / 1, scan horizon 60, audit horizon 366, `blocked_nights: exclude`,
  re-check `fix` with epsilon 0.005; per relation: enabled, priority 100, stages `[coherence]`, scan `horizon`,
  strategy nights `apply`, empty scope, no order.
- `Config\RelationFields` describes every field (type, range, default, label, help) as `Config\Field` trees:
  `general()` (keys `relations.*`, ready for the rule set schema), `relation()`, `params(type)` and `priceBand()`
  (keys relative to an item: the params depend on the type, so they are not one static schema tree).
- `RelationValidator::validate(config, rooms, propertyIds)` checks what needs the rooms, before saving:
  `relations.unknown_property`, `relations.unknown_room` (target, references, explicit set rooms; a room of
  another property is unknown), `relations.self_reference`, `relations.target_in_set`, `relations.bad_link`
  (`cap_relation` must be an enabled band of the same target; `suspends` other enabled relations of the same
  target), `relations.cycle` (message with the closed walk of rooms and the relation ids). Inverted ranges
  (`position_min > position_max`, `lower_percent > upper_percent`, `min_percent > max_percent`, scope days and
  periods) are refused while reading. Every enabled relation counts for the cycle check, whatever its scope.

## 3. Resolution order (`RelationGraph`)

Edges `X -> target` for every room whose **price** a relation reads (`at_least`: reference; `between`: its
references; `premium_over_max` and `discount_band`: the set; `occupancy_switch`: the set of its `cap_relation`;
the trigger set is occupancy, not price, and adds no edge). Groups are the connected rooms (trigger sets
included). Relations sort by (rank of the group = first room of the group in the room list, depth of the target,
priority, position of the target, id): a group runs as a block and a relation runs after every relation that
targets one of its inputs. A cycle makes the graph refuse the configuration.

## 4. Coherence stage (`Coherence\CoherencePass`)

Runs after the engine and before the listing-maximum pass, on the plan draft (`PlanDraft` of `DraftItem`) and on
`NightFacts` (calendar rows, prices decided in the run, ladder prices, light filter, nights blocked in the PMS).

- **Nights** (`NightSet::of`): the nights the engine touched, plus, in a full run, the calendar nights of every
  `horizon` relation's target from today to `scan_horizon_days`; in a light run, the nights of the filter
  instead. The pass runs only on the nights it is given.
- **Gating** per night and relation: scope; `scan: touched` needs a decided price in the relation's group at the
  start of the pass; `strategy_nights`; a target without a calendar row is `no_data`; `requires_order` must be
  active (`not_authorised`); a switch that holds the night suspends the relations it lists.
- **Chain types** (`TargetAdjuster`): a rule already met never creates an item (trigger tolerance); a floor only
  rises (on the target's percent grid: never below the amount asked after rounding up) and a ceiling only falls;
  a ceiling below a raised floor is lifted to it; the next relations see the target's **effective** floor. Owner
  frozen or fixed nights and sold-out targets are skipped. The target's own floor beats a `between` band
  (warning `relation.ceiling_raised_to_floor`); a premium floor above the room's price band ceiling lifts the
  ceiling (`relation.floor_over_band_ceiling`).
- **Band**: the value is chosen directly on the target's percent grid; an item of the target only chooses inside
  the band; a night without an item is touched only outside `[lower, upper + one grid step]`; down-only night
  types never raise and stop at the target's floor; an owner order floor above the upper edge gives way
  (`relation.order_floor_yielded`); on a ladder night of a member the target's pace step is dropped
  (`relation.engine_step_discarded`); the target's floor wins over the upper edge (`relation.floor_above_band`).
- **Switch**: trigger by percent (with the 1e-9 guard) or units; a trigger room without a row counts its units
  and no sale; price on the target's grid, never below `FloorSource::switchFloor`, capped by the band's upper edge
  while a member is free; no market data falls through to the band (`relation.switch_no_market_data`).
- **Outcomes** (`Outcome`, recorded in `RelationReport` for every relation and night): `satisfied`, `applied`,
  `no_data`, `empty_set`, `skipped_*`, `satisfied_down_only`, `floor_blocks_lowering`, `not_authorised`,
  `not_triggered`, `no_market_data`, `switch_applied`, `switch_satisfied`, `switch_held_by_owner`.

**Satisfied by the occupancy switch (operation of 12/11).** When the switch holds and the target is already at
the switched price (current price, floor and ceiling in force within one grid step), nothing is written, as in
the reference engine, but the report records the explicit decision `switch_satisfied`
(`RelationReport::satisfiedBySwitch(room, night)` gives the switch id). The validator rule (section 8) accepts
that night below the band, with a warning, instead of the engine's false "below the band" error.

## 5. Currency re-check after percent rounding (`RoundingRecheck`)

Each floor goes out as a whole percent of its own room's base, so a target set equal to its reference can come
out one point above it. `check(relations, nightType, percents, fixed)` converts the percents of one night back
to currency and checks `between` (target not above the high reference) and `at_least` / `premium_over_max` (not
below the requirement, without epsilon). Mode `fix` lowers the target one point at a time (never below the low
reference nor below its `lowest` percent; `relation.rounding_unsolvable` when the references are closer than one
step) or raises it to the requirement; `warn` only reports; `off` does nothing. Bands and switches are exempt.
To be called by the payload builder, per night, with the relations that hold that night.

## 6. Audit (`Audit\RelationAuditor`, `Audit\PremiumAudit`)

Over a long range of the **current** calendar prices (the safety net for nights the engine did not touch):
`run(range)` lists the nights a target is out and `toPlanItems()` proposes items. Kept as in the engine: the
middle room is out only beyond the whole gap widened by the audit tolerance; a night with any of its three rooms
sold out is not reported; a night missing a row skips the whole group; the top room compares with the members
that are not audited targets plus the corrected values of the audited ones (0 when sold out), needs a free
anchor, adds the derived `at_least` on ladder nights and gets its band ceiling up to the band's horizon; bounds
are whole currency, half to even. The middle room's item cites the order that allows going below the owner's
floor when its floor is below it, else its relation's order; the top room's item cites none.

## 7. Listing-maximum chain and bounds of the top room

- `ListingCapChain::capFor(room)`: the highest effective floor under the listing maximum, chained through the
  `between` (path) relations: high reference, then target, then low reference. `apply(draft)` is the last pass:
  floors above the cap are cut to it; the ceiling of an item at the cap (within one grid step) follows, except a
  sold-out protection.
- `PremiumBounds::forNight()`: floor and ceiling of the target of a premium relation BEFORE the coherence stage
  (for the pace step and the sold-out protection): dearest member not sold out (decided price, else calendar) x
  (1 + premium); with a price band tonight (up to its last night) the floor is at least the band's low end and the
  ceiling its high end.

## 8. Validator rule of the bands (`Validate\DiscountBandCheck`)

`check(PlanNightValues, ?RelationReport)` on every night the plan has an item of a band's target or member:
above the cheapest free member is always an error (`band.above_reference`, and `band.ceiling_above_reference`
for a ceiling); above the upper edge a warning (`band.above_upper`); below the lower edge an error
(`band.below_lower`) unless a switch of the target holds the night with its trigger **proven by the fresh
calendar** and either the item cites the switch's order or the coherence report says `switch_satisfied`
(`band.below_by_switch`; also when the switch's item was dropped for having no effect, ENGINE.md 1.8, L5), an owner
order covering the night releases the discount band (`discount_band`, `band.below_by_order`), or the value is a percent
floor against a reference that is not in the plan (`band.below_confirm_after_recalc`). Down-only night types are
not checked below. The scope check of the switch's order on other items (`switch.not_proven` in the spec)
belongs to the order rules of the validator and is not here.

## 9. Hooks and their neutral answers

Relations ask other subsystems through `Port\*` (bundled in `Port\RelationHooks`): `NightCalendar` (night type),
`OrderGuard` (frozen, owner price, floor order, active order, release), `StrategyView` (ladder governs / pins),
`FloorSource` (floor in force, lowest allowed, between hints, switch floor, rule floor, safety exception),
`PriceBandSource` (band of a room, last night) and `MarketTargetSource` (switch market price).
`Neutral\NeutralHooks::for(rules, config, rooms)` answers for a site without owner orders, ladder or market data:
hard floors (`Pricing\Floors`), switch floor = safety limit (and the general holiday floor on holidays), bands from
`price_bands` converted with the property's price factors and rounded half to even, nothing frozen or ordered,
no market data. `Port\BetweenHints` carries the ladder's additions to a `between` target: own floor, standard
floor, the order that lowered it (cited only when it moved the lower edge by more than 0.5), own target ceiling
and "hold the current price".

## 10. Parity with the reference engine

- Parity fixture: `tests/fixtures/presets/parity-relations.json`, generated from the frozen engine configuration
  (`tests/fixtures/frozen/quartos.json`) and the engine's constants. First property: low room <= middle room
  (`between`, up to 75% of the way) <= high room (`at_least`, the last unit of the low room does not pull it) <
  top suite (`premium_over_max`: 20% on weekday nights, 5% on Friday, Saturday and holiday nights, + 0.5; and 15%
  over the high room on ladder nights). Second property: the room without a tub between 85.5% and 90% of the
  cheapest free tub suite (`discount_band`, down only on holidays), and at 80% of the tub units sold the market
  price of rooms without a tub (`occupancy_switch`, capped by the band, suspending it). One price band (weekend
  band of the top suite). Settings: `blocked_nights: ignore`, re-check `warn`.
- Goldens: `python3 tests/fixtures/tools/golden_relations.py` (add `--freeze` to copy the engine data again, read
  only) writes `tests/fixtures/golden/m2/relations/*.json` and `MANIFEST.json` (engine commit, `engine_dirty`,
  hashes of the frozen inputs and of the parity fixture, case counts). Inputs: the M1 frozen configuration plus
  `golden/m2/relations/frozen/` (owner ruler by night, seasonality, the four standing orders: numbers and config
  values only). The generator records, per case, the answers of the other subsystems computed with the engine's
  own helpers ("hooks"), so the PHP test (`tests/Unit/Domain/Relations/Golden/RelationsGoldenTest`) checks the
  graph alone with the same answers.

| Golden file | Cases | PHP |
|---|---|---|
| `pace.coerencia` | 179 | `CoherencePass` + `NightSet` (night set and night types checked too) |
| between-rooms order: band (`faixa`) | 48 | `RelationAuditor::auditBetween` |
| between-rooms order: premium band | 44 | `PremiumAudit::check` |
| between-rooms order: `comparar` (+ `montar_plano`) | 8 | `RelationAuditor::run` + `toPlanItems` |
| pace: premium room rule | 22 | `PremiumBounds::forNight` |
| `pace.teto_anuncio` | 44 | `ListingCapChain::capFor` |
| `pace.limitar_ao_maximo` | 9 | `ListingCapChain::apply` |
| plano_dono: occupancy switch trigger | 44 | `SwitchParams::isHit` |
| validar_plano: discount-band check | 34 | `DiscountBandCheck::check` |

Amounts within 0.01; flags, rooms, nights, order ids and issue codes exactly. The configured price bands are also
checked against the engine's bands on every recorded night.

## 11. Divergences from the reference engine (deliberate)

1. **Night held by the switch without an item** (12/11): the engine's validator reports "below the band" for a
   night whose room without a tub is already at its market price (no item) when a member has an item; the plugin
   accepts it with `band.below_by_switch` when the coherence report says `switch_satisfied` and the trigger is
   proven (golden cases `switch_satisfied_*`, with `php_expected`). Without the report, or without the proof, the
   plugin reports what the engine reports.
2. **Comparison plan**: every relation compares in the sent plan (as the engine does); a per-relation
   `price_plan` is not offered in M2 (unknown field on import).
3. **Switch `then.kind: band`** (another band of the same set while the switch holds) is not offered in M2;
   `market_percentile` and `hold` are.
4. **Gating**: a relation whose target has no calendar row reports `no_data`, and `requires_order` is checked for
   every relation before its rule (the engine only has such a relation for the switch). Same prices and items.
5. **Neutral defaults differ from the parity values** where the spec chose so: `blocked_nights: exclude` (parity
   `ignore`) and re-check `fix` (parity `warn`).

The rest follows the code of the engine, including the rule-text divergences the spec lists (tolerance of 1% of
the base + 1, band pinned floor = ceiling, + 0.5 on the premium floor, switch floor = safety holiday floor).

## 12. Open questions of the specification: choice made (the engine's current behaviour)

1. Raising tonight's price by a relation: the coherence stage raises D0 as the engine does; the safety stage
   (`safety.never_raise_today`, Pricing) decides afterwards.
2. Re-check mode after cut-over: parity `warn`, neutral `fix`; switching the owner's properties is the owner's call.
3. References closer than one step of the middle room: kept one step above the high room, reported as
   `relation.rounding_unsolvable`.
4. Whole set sold out: `skip` by default (the engine); `use_sold_out` exists as an option.
5. Audit against coherence: the audit keeps its own skips and the top room's item without an order (section 6).
6. Blocked rooms as references: parity `ignore` (engine), neutral `exclude`.
7. "80% of occupancy": the tub suites of the same property on the night, blocked units counted as sold when the
   pricing system reports them so (engine).
8. Holiday floor of the switched price: `FloorSource::switchFloor` = safety limit and the general holiday floor
   (engine), not the period's own floor.
9. Cycle check and scopes: conservative, every enabled relation counts whatever its scope.
10. Relations across properties: refused (a room of another property is unknown).
11. Market segments: the switch asks `MarketTargetSource`; segments and the measured factor per night belong to
    the market module (parity: the engine's ruler, recorded).
12. Storage: relations and price bands live in the rule set document (`relations`, `price_bands`); a table would
    be a projection.
13. Weekend band factor: the plugin converts with `price_factors` (public, weekend) of the property, equal to the
    engine's factor today (checked by the golden test).
14. Minimum distance between the low and the high room: possible as `at_least` with a margin; not in the parity
    fixture.

## 13. Integration (done in M2)

- Schema: the relations and `price_bands` are sections of the rule set (`RelationFields`), validated on save;
  `parity-relations.json` is merged into `tests/fixtures/presets/parity.json`.
- Pipeline (`Plan\Assembly\PlanAssembler`, ENGINE.md section 1.8): after the pricing of every room night,
  `NightSet::of()` (at most the run's horizon) then `CoherencePass::run()`, whose `RelationReport` goes to the
  validator, then `ListingCapChain::apply()`; the pace step and the sold-out protection use `PremiumBounds` for the
  premium relations; `Plan\Validator` calls `DiscountBandCheck::check()` with the run's report; work items take back
  what the coherence did (`DraftItem` reasons become the `RELATION_*` codes of the item).
- Not wired: `RoundingRecheck` (see VALIDATOR.md, section 7).
