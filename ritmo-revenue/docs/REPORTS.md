# Ritmo Revenue: reports (M4)

The owner's weekly and monthly reading: occupancy of the next nights, the month, the next 12 months against the
market, the revenue targets and forecast, the weekly panel and the run reports. Reports never change a price; their
inputs are the bookings the runs stored (by id and channel only: there is no guest field anywhere), the PMS inventory,
the targets the owner types and the rule set's `reports` section. Spec: `06-market-reports-stay.md` part B and
`05-admin-ux.md` 5.15.

## 1. Layers

| Layer | Classes | Rule |
|---|---|---|
| `Domain\Report\*` | `Booking\BookingLine`, `BookingTotals`, `Grouping`; `Month\MonthPerformance`; `Review\TwelveMonthReview`, `ReviewDue`, `ReviewSnapshot`; `Targets\RevenueForecast`, `RevenueTargets`, `HolidayForecast`; `Weekly\WeeklyPanel`; `Occupancy\OccupancyTable`, `OccupancyMap`, `RoomNight`; `RunLines\RunLine`, `RunReportItems`; `Config\ReportsConfig`, `ReportsFields` | Pure functions over immutable inputs; no clock, rounding by `Num`, days by `Days`, the property's timezone. |
| `Application\Report\*` | `ReportService`, `ReportScope`, `ReportSettings`, `Store\RevenueTargetStore`, `Store\SnapshotStore`, `Store\TargetRow` | Read the repositories (prepared statements only) and call the domain. |
| `Admin\Reports\*` | `ReportsScreen`, `ReportsActions`, `ReportViews`, `WeeklyTables`, `MonthTables`, `ReviewTables`, `OccupancyTables`, `ReportTable`, `Cell`, `BarChart`, `ReportsText`, `RunLineText`, `WriteReasonText`, `ReportsServices` | The screen, its forms and CSV, and the words. |
| `Rest\Reports\*` | `ReportRoutes`, `ReportEndpoints` | `ritmo/v1` routes. |

## 2. Common conventions

- A **booking line** (`BookingLine::of`, reference `desempenho_mes.linha`): property (through the room), room, status,
  channel (`booking`, `airbnb`, `expedia`, any other in lowercase; `direct` split into `direct_api` when the origin is
  exactly `API`, else `direct_manual`), arrival, nights, units, revenue (`BookingRecord::$total`: charge items without an
  invoicee, else the price), creation instant, lead days at the property, cancellation instant (`cancelledAt`, else the
  modification of a cancelled booking).
- Counted: `confirmed` and `new` (active) and `cancelled`; requests, blocks and inquiries are left out.
- Capacity of a property: the units of its rooms. Money: 2 decimals, half to even. Shares follow the reference's
  decimals (occupancy 3, achieved 3 or 4, weekly occupancy 4).
- `ReportService` reads the stored bookings whose stay overlaps 400 days around today (the bookings purge keeps what
  the runs need; a booking made in the month for a stay far beyond that window is not counted).

## 3. Occupancy (RP-1 to RP-3)

`OccupancyTable::of` gives the windows of the goals (7, 15, 30, 45, 60 nights from today) for each room, the site (more
than one property), each tag group (`reports.occupancy.groups[]`) and each property. `RoomNight::of` counts a night:

- with the bookings read: sold = min(units, booked); a night is **blocked** when the PMS reports nothing available while
  fewer units than the room's are booked; a blocked single-unit night is out of both counts (never for sale), a blocked
  multi-unit night counts its sold units over its units;
- without the bookings: sold = units - available (M1): a night closed without bookings counts as sold.

A night the PMS does not report is out of both counts. `OccupancyMap::of` gives the next `reports.occupancy.map_nights`
nights room by room: free units (0 when blocked), sold, units, the calculated and sent prices, the minimum stay and the
demand label (from the pricing calendar when the run stored it: see section 12), the weekday and the holiday.

`OccupancyWindows` (M1) is unchanged; the integration makes it delegate to `RoomNight` with the booked units (section
12).

## 4. Month performance (reference `desempenho_mes.calcular`)

`MonthPerformance::of(lines, month, asOf, capacities, targets, timezone)`: bookings made from the 1st at 00:00 at the
property to `asOf`, per property and for the site (`MonthReport::SITE`), overall and by channel, room, week of the month
and month of the stay (`BookingTotals`: bookings, cancelled, active, room nights, revenue, daily rate, mean lead time,
mean stay, cancelled revenue, cancelled without value); cancellations made in the month (count, revenue, how many of
bookings made before it); the stays of the month per property: realised (nights before today), on the books (today to
the month's end), the month's totals, the owner's minimum, the gap, the room nights still free and the daily rate they
need.

## 5. 12-month review (reference `revisao_12m`)

`TwelveMonthReview::of`: the current month and the next 12 per property: sold revenue, occupancy and daily rate, the
minimum and the realistic target, the share of the minimum, the pickup since the previous snapshot (the newest taken
before today), the market's sold occupancy now and last year (typed on the targets tab: RP-X5), the reading (none for the
current month; `early` below `reports.review.early_market_percent`; else own occupancy x 100 / market percent:
`ahead` >= `ahead_ratio`, `behind` <= `behind_ratio`, else `on_pace`) and the average sent price with its change.
`ReviewDue`: never taken, or `reports.review.interval_days` or more since the last. "Save this review" stores a
`ReviewSnapshot` (totals only) in `report_snapshots`.

## 6. Targets and forecast (reference `metas_faturamento`)

Typed per property and month (`revenue_targets`, `TargetRow`): minimum, realistic, last year's market occupancy and
daily rate, occupancy cap (a factor), the adjustment of each scenario, the market's sold occupancy now and last year.
`RevenueForecast::months`: per month and scenario (`floor`, `base`, `goal`), the sum over properties of
`occ LY / 100 x ADR LY x growth x index x units x days x cap` (multiplied in the reference's order) + the adjustment,
never below the revenue on the books; a property without last year's market is listed as missing.
`RevenueTargets::of`: per month and property, today's forecast (base index), realistic (goal index), maximum (maximum
index), all with `reports.forecast.realistic_growth`; the minimum typed, else the season's
(`reports.forecast.high_months`, `season_minimum`); the RevPAR of the minimum; the minimum against the realistic.
`HolidayForecast::of`: sold revenue + max(0, target occupancy x room nights - sold nights) x daily rate;
`HolidayTarget::belowFloor` finds a rate below the rule set's holiday floor (refused when saved).

## 7. Weekly panel (reference `painel_semanal.montar`)

`WeeklyPanel::of`: bookings made per week (Monday to Sunday at the property) from `reports.weekly.start_date` (its
Monday; null = 26 weeks back) with the cancellations made that week; stays per stay week (8 back, 8 ahead, not before
the start) with occupancy, revenue, daily rate and RevPAR; the current month and the next two against the minimum; and
the bookings of the last 28 days per channel and per room with their share of the property's active revenue.

## 8. CSV

Every table of the screen downloads as CSV (`ReportsActions`, action `ritmo_export_report`, `ritmo_view`, nonce): the
UI kit's `CsvWriter` (UTF-8 with BOM, comma, translated header, ISO dates, numbers with a dot, a currency column when
money appears). Shares are written as percentages with 2 decimals. Formula guard: every text cell starting with `=`,
`+`, `-`, `@`, a tab or a carriage return gets an apostrophe; numbers the writer formats stay numbers (RP-D4). Nothing is
written to disk. File name `ritmo-<table>-<property>-<date>.csv`.

## 9. Screen, actions and REST

Screen `Menu::REPORTS` (`ReportsScreen::screens()`), tabs: Weekly performance, Month and goals, Channels, Rooms, Next 12
months, Targets, Occupancy, Run reports; every tab shows a summary sentence first (its numbers are those of the tables'
totals), the tables (caption, `th scope`, totals row where it adds up), the weekly occupancy as inline SVG bars
(`aria-hidden`, the table is the source) and the CSV; an empty state when there is nothing yet. The tables become cards
below 600 px (`assets/reports.css`); `assets/reports.js` only moves the focus to the summary after a month is chosen.

Form actions (`ReportsActions::actions()`): `ritmo_export_report` (view), `ritmo_review_snapshot` and
`ritmo_targets_save` (manage rules). REST (`ReportRoutes::register()`, nonce and capability through
`RestRoutes::allowed`): `GET /reports/{occupancy|month|review|weekly|channels|rooms}?property=&month=`,
`POST /reports/review/snapshot`, `GET|PUT /reports/targets`, `GET /runs/{id}/report` (each line's code, parameters and
text in the reader's language). Answers carry sentences and formatted cells, never internal names; errors are
sentences without internal data.

## 10. The run report as translatable lines (RP-4)

`RunReportItems::of(RunFacts, staleAfterDays)` gives the coded lines (`RunLineCode`: `light_idle`, `sync_now`,
`security_findings`, `overbooked`, `wave_hold`, `shadow_would_write`, `waiting_approval`, `gate_refused`, `written`,
`write_stopped`, `held`, `validator_refused`, `delivery_overdue`, `market_stale`, `pms_skipped`, `stay_merge_conflict`),
each with exactly its parameters (counts, or reason codes in lowercase); anything else is refused, so no guest field can
ride along. `RunLineText` renders them with `__()`/`_n()` and numbered placeholders; an unknown code is "Unknown report
line"; runs stored before keep their English lines, shown "(recorded before translation was available)".

## 11. Configuration (`reports`) and storage

`ReportsFields` / `ReportsConfig::fromArray` (neutral defaults; parity in `tests/fixtures/presets/parity-reports.json`):
`occupancy.map_nights` (1-60, 14), `occupancy.groups[]` (`label`, `tags`), `review.interval_days` (1-90, 15),
`review.ahead_ratio` (1-5, 1.2), `review.behind_ratio` (0.1-1, 0.8), `review.early_market_percent` (0-50, 5),
`review.price_tags`, `weekly.start_date`, `forecast.scenarios.{floor,base,goal}.growth` (0.5-2) and `.index.<property>`
(0.1-5), `forecast.realistic_growth`, `forecast.maximum_index.<property>`, `forecast.high_months`,
`forecast.season_minimum.{high,low}.<property>`. Migration draft `DraftReports`: `report_snapshots`, `revenue_targets`,
`holiday_targets`.

## 12. Parity, goldens and divergences

`tests/fixtures/tools/golden_reports.py` (guards: no bytecode, no network, no write under the skill, `dados/` reads
redirected to the invented frozen targets) writes `tests/fixtures/golden/m4/reports/` (identical to the spec's draft):
`ocupacao.calcular` (2), `desempenho_mes.linha` (40), `agrega` (4), `calcular` (4), `revisao_12m.meses` (4),
`devida` (4), `rodar` (2), `painel_semanal.montar` (2), `metas_faturamento.feriados`, `meses`, `por_pousada` (2 each).
The tests in `tests/Unit/Domain/Report` compare money within 0.01 and percentages and counts exactly; the level-1
occupancy golden passes with the booked units too.

| # | The plugin | The reference |
|---|---|---|
| RP-X1 | Blocked from the PMS inventory and the booked units. | From the pricing calendar's unavailable flags. |
| RP-X2 | The property's timezone. | Fixed UTC-3. |
| RP-X3 | A cancellation at exactly `asOf` counts (instants compared). | Text of 19 characters compared. |
| RP-X4 | The month adjustments are typed (2027-03 and 2027-04 of the module inputs are reproduced with typed adjustments; 2026-10, a replacement, is not compared). | Hard-coded months. |
| RP-X5 | Market occupancy typed or imported. | Read from the pricing system's neighbourhood data. |
| RP-X6 | Average price of the rooms with the configured tags. | Rooms with the bathtub flag. |
| RP-X7 | Site total and tag groups. | Hard-coded groups. |
| RP-X8 | Weekly start configurable (its Monday). | Fixed start. |
| RP-X9 | A booking without a creation instant has no lead time and is out of the mean lead time. | Read as made in 1970. |
| RP-X10 | The sent price of an unavailable night is no price (null). | -1. |

Open (integration): the review's average sent prices and the map's prices read the pricing calendar, which the runs do
not store yet (`pricing_calendar` of spec 05): until then the review shows no average price and the map no price; the
holiday targets have their table but no editing form yet; a targets save is not written to the change log yet.
