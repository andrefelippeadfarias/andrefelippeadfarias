# Ritmo Revenue: stay and arrival/departure rules (M2)

This document covers `src/Domain/Calendar/Stay`: the minimum stay by arrival day, closed and open weekdays, orphan
nights, holidays and seasons with their own stay, closed packages, the audit of the PMS inventory, the stay plan for
the pricing system and the single PMS batch. It uses the holiday periods of the rule set through
`Calendar\HolidayCalendar` (M1) and nothing else of the engine.

All of it is pure PHP and read-only for external systems: the audit and the plan return change objects
(`StayChange`, `ArrivalDepartureChange`) for the milestone that writes (M3). No class here holds a connector, a
`WriteToken` or an HTTP client.

Reference engine: `calendario_12m.py` (`classificar_beds24`, `comparar` with `--so-estadia`, `montar_plano`,
`lote_beds24`, `lote_unico`, `estadia_da_noite`, `prova_sabado_orfao`, `Ocupacao`, `sabado_com_sexta_passada`),
`comum.py` (`regra_dia_chegada`, `estadia_do_dia`, `estadia_pacote`, `data_de_pacote`, `orfa_permitida`,
`sexta_reaberta`, `aguardando_dono`) and `validar_plano.py` (`estadia_exigida`, `regra_do_dia_decide`,
`noite_orfa_decide`, `estadia_alvo`, `estadia_planejada`, `periodo_temporada`). The owner's reasons are Regra A and
Regra H of `references/regras.md`.

## 1. The rules of one night (`StayRules`)

`StayRules(StayConfig, HolidayCalendar, list<RoomType>)` answers, for a room and a night:

| Method | Answer |
|---|---|
| `requiredStay(room, night)` | The minimum stay the night requires, or null. Highest first: the package night's minimum; in a holiday period its own stay, else the stay of the first season covering the night, else `holiday_min_stay`; else the arrival weekday's stay raised to the closed day's own stay and to the season outside holidays (a season whose dates overlap any holiday period does not raise). With the arrival rule off, a closed day keeps its own stay and other nights require nothing. |
| `plannedStay(room, night, current, free, nextSoldOut)` | `PlannedStay(minStay, orphan)`: where the arrival rule decides, up to the target, down only to one night (target 1 or orphan night), and a stay above a target of two or more stays. In a holiday period only the orphan night is decided. null elsewhere. |
| `arrivalDecides`, `isHeld` | The arrival rule decides nights outside packages, holiday periods, closed days with their own stay and held weekdays (`isHeld` is true for a held night the rule would otherwise decide). |
| `orphanDecides`, `orphanAllowed(room, night, closedDaySide)` | Where an orphan stay fits: never on a package night nor on an open date of the closed day; in a holiday period only when `orphan.on_holidays` (orphan night) or the closed day's `orphan_side.on_holidays` (its free side). |
| `closedDay`, `openDayApplies`, `packageNight` | The first closed-day rule of the room on that weekday; whether the weekday is enforced open for the room (never on a package night or where a closed-day rule applies); the package night. |
| `closedDayWithPreviousPast(room, night, today)` | Today's closed day whose previous night has passed: that night no longer sells and counts as sold out. |
| `seasonOutsideHolidays`, `arrivalWeekdayStay` | The season that raises the arrival rule; the weekday's stay (room over property over site). |

An **orphan night** is a free night whose next night is sold out: an arrival there fits only one night, so it takes a
one-night stay. An **isolated night** (free between two sold-out nights, already at one night) is never raised. The
**orphan side** of a closed day: the closed day sold out with the night before free opens its departure
(`when_closed_day_sold_out`, the night before sells alone); the night before sold out (or past, on today's closed day)
with the closed day free opens its arrival (`when_previous_sold_out`) and the closed day sells with one night.

Free and sold out come from one source per room (`Occupancy`): the PMS inventory when read (units for sale above zero
is free, zero or overbooked is sold out, an unreported night is neither), else the pricing calendar (sold out when
every unit is sold). An orphan change carries the occupancy as read (`OrphanProof`: source, units or units sold, both
nights, the read instant, and the past night's date on today's closed day) for the validator of M3.

## 2. Audit of the PMS inventory (`InventoryAudit`)

`audit(inventory, from, to, today, readAt)` takes `room code => 'YYYY-MM-DD' => InventoryNight` and returns one
`RoomAudit` per room, sorted by code. Per night, highest rule first:

1. **Package night**: its minimum and maximum stay (`packageStays`) and, when it has a restriction, that restriction
   (`arrivalChanges`, reason `package`); nothing else applies to it.
2. **Closed day**: the rule's restriction, an open date's own restriction, or its orphan side's; the free closed day's
   one-night stay (`orphanStays`: for the PMS with a proof when the night before has passed, otherwise for the pricing
   system); its own stay reported when below (`closedDayStayBelow`).
3. **Enforced open day**: an arrival/departure restriction there is cleared (reason `open_day`).
4. Any other restriction is reported, never changed (`otherRestricted`).
5. **Arrival rule**: the planned stay against the PMS stay (`arrivalStays`, reason `arrival_day` or `orphan_night`).

A **blackout is never changed**: it is availability, not an arrival/departure rule (`ArrivalDepartureChange::forPms`
refuses it). Prices and units for sale are never part of a change.

**"Also pricing".** When the pricing system pushes a stay field to the PMS, a change written only to the PMS is undone
by the next push. A change carries `alsoPricing = true` when the pricing connector can carry that field
(`StaySyncCapability`, declared by the connector) and the configuration says the push is on for the room
(`stay.pricing_sync.<field>_rooms`, `"*"` for every room). The reference engine's `pricelabs_tambem` is this flag.

## 3. Stay plan for the pricing system (`PricingStayPlan`)

`plan(room, calendar, occupancy, from, to, today, effective, dateRules, stayOnly = true)` compares, per night, the
stay in force (the effective stay when read, else the calendar's) with the rules and returns `PricingStayEntry`
objects: the findings (`StayFinding`; issues and accepted reasons) and the items for the date override of that night:

- a `StayChange` (target `pricing`) with the new minimum stay, its reason (`arrival_day`, `orphan_night`,
  `explicit_rule`, `closed_day_orphan`, `own_stay`), the orphan proof and the season name when the season set it;
- an `ArrivalDepartureChange::forPricing` with both weekday masks when an enforced open day is closed in the date
  rule's masks (or the listing's, when the date rule has none);
- `keepReason` on both: the item leaves the date rule's reason alone when that rule explains something the item does
  not change (a price, floor or ceiling, masks the item does not rewrite, or a reason containing one of
  `stay.keep_reason_markers`). Without the date rules read, a mask item keeps the reason.

With the date rules read, a night whose required stay is two or more gets it written explicitly on the date rule
(`explicit_rule`) when the rule holds less, because a pricing system's default stay changes by itself. Closed days,
holidays and packages below their own stay are reported in the stay-only plan (`own_stay_outside_stay_plan`) and
raised by the full plan (`stayOnly = false`). Floors are not here: they belong to the pricing engine.

## 4. The single PMS batch (`StayBatch`) and the stay hook (`StayHook`)

`StayBatch::single(audit, today, stayOnly)` lists every change of the audit once: restrictions, arrival-rule stays,
today's closed-day orphan stays and package stays (stay-only: the two middle ones). The urgent ones (today to
today + `stay.batch.urgent_days`) come first by night, property and room; then the rest by property, room and night;
changes of one room and night keep that order. `blocks(changes)` cuts the list into requests of at most
`stay.batch.max_ranges` ranges; the writer (M3) re-reads each block and skips a change whose `minStayBefore` no longer
matches the PMS.

`StayHook` is what the pricing plan asks when it deletes a date rule and writes it again (to free a ceiling,
`pace.ajustar_tetos`): `nightStay()` (the planned stay and orphan proof), `closedDayOrphanProof()` and `rewrite()`
(inherited stay and departure mask corrected: the enforced open day opened, the planned stay applied, a closed day back
to its own stay unless it is the proven free side).

## 5. Configuration (`stay`)

`StayConfig::fromArray($section)` fills missing fields with the neutral defaults, checks the section strictly
(`SectionCheck`, the rules of `RuleSetValidator`) and throws `InvalidConfig` with every error. `StayFields::all()`
describes each field (type, range, default, label, help). The defaults switch everything off.

| Key | Type (range) | Default | Meaning |
|---|---|---|---|
| `arrival_rule.enabled`, `valid_from`, `valid_to` | bool, dates | off, open | The minimum stay by arrival weekday and the nights it covers. |
| `arrival_rule.by_weekday` | map `0`..`6` => nights (1-30) | 1 every day | Monday = 0. |
| `arrival_rule.properties`, `rooms` | property id / room code => weekday => nights | {} | Per-property and per-room exceptions; the room wins. |
| `arrival_rule.held_weekdays` | room code => weekdays | {} | Weekdays left as they are until a decision. |
| `orphan.next_night`, `orphan.on_holidays` | bool | off | The orphan night sells with one night; also in holiday periods. |
| `holiday_min_stay` | int 1-30 | 1 | Stay of a holiday night whose period and season set none. |
| `closed_days[]` | `weekday`, `restriction` (no_checkin, no_checkout, no_checkin_checkout), `rooms`, `except_rooms`, `min_stay` (own stay or null), `open_dates` (date => restriction), `orphan_side` (`enabled`, `on_holidays`, `when_closed_day_sold_out`, `when_previous_sold_out`) | [] | Weekdays closed for arrival and/or departure. |
| `open_days[]` | `weekday`, `rooms` (at least one) | [] | Weekdays whose arrival and departure stay open in those rooms. |
| `packages[]` | `name`, `rooms`, `nights[]` (`date`, `min_stay`, `max_stay`, `restriction`) | [] | Closed packages; a night listed here is a package night. |
| `seasons[]` | `name`, `start`, `end`, `weekdays`, `min_stay` (or null) | [] | The first covering period is the night's season. |
| `pricing_sync.min_stay_rooms`, `max_stay_rooms`, `arrival_departure_rooms` | room codes or `*` | [] | What the pricing system pushes to the PMS. |
| `keep_reason_markers` | texts | [] | A date rule with one of these in its reason keeps it. |
| `batch.urgent_days`, `batch.max_ranges` | int 0-60, 1-1000 | 14, 100 | The urgent window and the ranges per request. |

Room codes and property ids are checked for format here; that they exist is the preset loader's job once the section
joins the rule set schema.

## 6. Parity

`tests/fixtures/tools/golden_stay.py` runs the reference engine's functions (imported, never run as a script; the
season calendar points at the frozen copy, the orders file at a missing path, and a guard raises if the engine reads
the clock) and writes `tests/fixtures/golden/m2/stay/*.json`, its `MANIFEST.json` and the parity fixture
`tests/fixtures/presets/parity-stay.json` (the owner's rules: one night on Sunday, Tuesday and Thursday, two on the
other days; Saturday closed for arrival and departure with its own stay of two, the festive Saturday
open and the orphan side on, also on holidays; Friday enforced open in one suite; the Christmas and New Year packages;
orphan nights on holidays allowed; the season calendar). `python3 tests/fixtures/tools/golden_stay.py freeze` refreshes
`golden/m2/stay/frozen/` (season periods and four seeded scenarios: a Thursday, today a Saturday, heavy sell-out,
July season).

`tests/Unit/Domain/Calendar/Stay/StayGoldenTest.php` checks every golden file exactly: `estadia_exigida`,
`estadia_planejada`, `orfa_permitida`, `sexta_reaberta`, `sabado_com_sexta_passada`, `estadia_da_noite`,
`prova_sabado_orfao`, `classificar_beds24` (auditar), `comparar` with `--so-estadia` (plano) and `lote_unico` (full and
stay-only), over the four scenarios.

**Deliberate divergences** (written in the test, applied to the expected values there only):

| # | The plugin | The reference engine | Why |
|---|---|---|---|
| D1 | A blackout night never gets an arrival/departure change. | A closed day's blackout is overwritten with the closure (`o != alvo`), which reopens a night someone blocked. | A stay rule must not change availability. |
| D2 | A package stay change carries the stay read (`minStayBefore`). | The package item has no `min_stay_antes`. | The writer skips a night changed since the read, as for every other stay change. |
| D3 | "No maximum stay" in the PMS is null. | It is read as 365 (`maxStay or 365`), so a package maximum of 365 would count as already set. | A missing value is not a number. |

**Generalisations** (same result with the parity fixture): the Saturday of the engine is any closed weekday, for all
or some rooms; Friday reopened for one suite is any enforced open weekday; the arrival table can differ by property and
room; the owner order that authorises the Saturday's orphan side in `comparar` is the configuration itself (the
goldens run the engine with that order in force); the season calendar is configuration (`seasons`); the shortened
season name of the engine's reasons is the season's name; the closed-day side of `orfa_permitida` is asked only of a
closed day (the engine answers for any night); free-text reasons become reason codes.

**Not ported** (legacy or another module): the weekday stay of the season calendar for nights before the arrival rule
(`semana_estadia_acima`, only for nights before the arrival rule starts), the old Saturday swap table (`troca_sabado`, unused when
the Saturday closure is set), `dso_flag` and `preco_antes` (report fields), floors of `comparar` (pricing engine) and
the stay checks of `validar_plano.Validador` (validator).
