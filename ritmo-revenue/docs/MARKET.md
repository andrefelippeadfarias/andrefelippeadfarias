# Ritmo Revenue: the market (milestone M4)

The market is what competitors charge per night. The plugin keeps the competitors of each property
and their prices (typed on the Market screen or imported from a CSV file), builds price series per
night from them, and hands those series to the sales ladder (the ruler) and the market anchors to
the pace. Nothing here changes a price: the next run reads the market like any other input, and
every write still goes through the run pipeline and the write gate of M3.

The rules of this document are the market part (A) of spec 06; the Market screen follows section
5.9 of the admin spec. The rule editors are in `EDITORS.md`.

## 1. Where each piece is

| Folder | Classes | What they do |
|---|---|---|
| `src/Domain/Market/Csv` | `MarketCsvReader`, `CsvColumns`, `CsvValues`, `CsvBytes`, `CsvContext`, `CompetitorIndex`, `ParsedRow`, `CsvReadResult`, `MarketCsvProblem` | Rows of strings to stay samples or problems with a line, a column and a code; the byte checks of a file (binary, code, encoding, delimiter). Pure. |
| `src/Domain/Market/Series` | `MarketConfig`, `MarketFields`, `SeriesDefinition`, `RoomBasis`, `CompetitorProfile`, `StaySample`, `NewestSamples`, `SampleWindow`, `ComparableFactor`, `StayValues`, `StaySeries`, `SeriesBuilder`, `TypeTable`, `TypeSummary`, `NightSeries`, `NightValue`, `Percentile`, `PricePosition`, `BandPosition`, `LadderSeriesCheck`, `MarketGaps` | Every rule of spec 06 1.3 to 1.5, the configuration section `market`, the check of ladder rules against the series, and the nights a ladder would price without a value. Pure. |
| `src/Application/Market` | `CompetitorService`, `CompetitorInput`, `MarketImportService`, `ImportPreview`, `UploadedFile`, `PreviewStore`, `MarketSeriesService`, `MarketRulerAssembler`, `MarketBoard`, `MarketExport`, `MarketFreshness`, `MarketReminder`, `MarketSettings`, `MarketRefused` | Transactions, the two steps of an import, the series cache, the ruler of a run, what the screen and the exports read, the reminder data. No WordPress. |
| `src/Infrastructure/Db/Repository` | `CompetitorRepository`, `StaySampleRepository`, `MarketImportRepository`, `MarketSeriesRepository`, `LadderReferenceRepository` | Prepared statements only, through the `Database` port. |
| `src/Infrastructure/Db/Migration/DraftMarket.php` | | Draft migration (the integration numbers it from 10): the new columns of `competitors` and `price_samples`, the tables `market_imports`, `market_series`, `ladder_reference`. |
| `src/Admin/Market` | `MarketScreen`, `MarketActions`, `MarketServices`, `MarketText`, `CsvUploadReader`, `TransientPreviewStore` | The Market screen, its form actions, every text in words, the one reader of an uploaded price file, the preview kept in a transient. |
| `src/Rest/Market` | `MarketRoutes`, `MarketEndpoints` | The market routes of `ritmo/v1` (section 9). |
| `templates/admin/market` | `screen`, `summary`, `competitors`, `prices`, `import`, `export` | Markup only; every value escaped. |

## 2. Data

- **Competitors** (`competitors`): name, a `code` unique per property (lowercase letters, digits and
  dashes; what a price file names first), a reference shown as plain text and never fetched or
  linked, the room compared, tags (`peer` marks the closest competitors; the peer series use only
  them), the comparable factor and its source (`measured`, `estimated` or not set), a rating shown
  only, active, and "own listing" (the property's own listing on a channel: never part of a series).
  A competitor with prices is never deleted, only archived; its prices stay for history and stop
  counting.
- **Prices** (`price_samples`): one row per stay (check-in and nights), never per night: the room
  basis (`cheapest` or `comparable`), the price per night (the total divided by the nights when the
  file gives a total), the total as given, the currency (always the property's), available or sold
  out (sold out is kept, never used), the source, the capture instant (UTC), the import it came from
  and a deduplication key (sha256 of property, competitor, check-in, nights, basis, source and the
  capture to the minute). M1 rows read as one-night comparable stays of their night.
- **Imports** (`market_imports`): who, when, the file's cleaned name, its sha256 and size, counts
  (total, imported, replaced, invalid, warned) and at most 200 problems as `{line, column, code}`.
  Never the file, never the content of a cell.
- **Series cache** (`market_series`): per night and series, the values per kept percentile, the
  competitors and stays, where the value came from and the oldest and newest capture. Rebuilt after
  an import, a competitor change, a change of the `market` section, and at the start of a full run
  when its inputs hash (newest sample, newest competitor change, the section, today) changed.
- **Reference prices** (`ladder_reference`): the sent price of each room and night for a sales
  ladder strategy whose opening is capped by its reference, taken once per strategy version and
  never edited; taking them again makes a new version and a change-log row.

## 3. The price file

One header row, then one row per stay. Column names are matched without case; the delimiter is
detected from the header (`,`, `;` or tab); UTF-8 (a BOM is dropped) or Windows-1252 (converted);
quotes per RFC 4180.

| Column | Required | Format |
|---|---|---|
| `competitor` | yes | code, then reference, then exact name of an active competitor of the property |
| `check_in`, `check_out` | yes | `YYYY-MM-DD`, at the property; check-out after check-in, at most `market.csv.max_stay_nights` nights |
| `price` | yes | `480`, `480.50` or `480,50`; positive; no thousands separator |
| `price_basis` | no | `total` (default) or `per_night` |
| `room` | no | `cheapest` (default) or `comparable` |
| `currency` | no | must be the property's |
| `captured_at` | no | a date, a date and time, or ISO 8601 with an offset; without an offset at the property; missing = the import instant (warning) |
| `source` | no | short lowercase word; default `csv` |
| `available` | no | `1/0`, `yes/no`, `true/false`; default 1 |

The template file of the Import tab (`ritmo_market_template`) has invented competitors only.

**Problems.** A file error (a required column missing, not text, not UTF-8 nor Windows-1252, too
large, too many rows, empty) imports nothing. A row error skips the row and is listed as "Line 14,
column check_out: the check-out must be after the check-in." with the cell cut to 40 characters; a
warning (old capture kept for history, duplicate line, capture missing, unknown column, guest-like
column) still imports the row. A column whose name looks like guest data (name, guest, email,
phone, document, CPF, passport, address) is never read: "not imported: the plugin never stores
guest data". Every code has its own sentence in `MarketText` (a test fails on a missing one).

**Deduplication.** A row whose key is already stored replaces that row (re-importing the same rows
changes nothing); a file whose sha256 equals an earlier import of the property is refused with
"This file was already imported on {date}."

**Two steps.** The upload is read and checked (`MarketImportService::preview()`); only the valid
parsed rows are kept, in a transient `ritmo_csv_<user>_<16 hex>` bound to the person, the property
and the file hash, for 30 minutes and at most 1 MB (otherwise "Split the file"); one preview per
person at a time. The screen shows the counts ("123 valid rows for 40 nights and 5 competitors; 3
lines ignored"), the first 50 rows, every problem (200 at most, then "and N more") and offers to
create the competitors the file names but the property does not have. "Import" (nonce and the
preview token, same person) checks the rows again, writes them in one transaction, records the
`market_import` event, and rebuilds the anchors and the series; "Cancel" drops the preview.

**Upload safety** (`CsvUploadReader`, the one reader of an uploaded price file): `UPLOAD_ERR_OK`,
`is_uploaded_file()`, the size (the setting `market.csv.max_bytes`, default 2 MB, never above
`wp_max_upload_size()`) before anything is read, a `.csv` name that WordPress also takes as CSV,
the first 8 KB sniffed (no NUL byte; not PHP, HTML, a script, a zip or a PDF), then the file read in
place with `fgetcsv` and never moved, copied, kept or served back.

## 4. From prices to series

- **Per stay and competitor** (`StayValues`, reference `faixas_dono.estadia_por_noite`): the
  cheapest value is the per-night price of its `cheapest` row; the comparable value is the price of
  its `comparable` row when there is one, else the cheapest value times its factor (the configured
  factor, or `market.comparable.default_factor`; an estimated factor above
  `market.comparable.estimated_factor_cap` is capped). Own listings, archived competitors, sold-out
  rows and competitors outside the property's list are left out. Only the newest capture of a
  competitor's stay and basis counts; a price counts for `market.max_sample_age_days` and only
  while its stay has not started.
- **Per stay and series** (`SeriesBuilder`, reference `faixas_dono.construir`): each configured
  series has a name, a basis and tags (empty = every competitor); a stay counts for a series with
  at least `market.min_competitors_per_stay` values; its percentiles are `Percentile::of` with the
  rank `(n − 1) × p / 100` and linear interpolation, rounded half to even.
- **Per night** (`NightSeries`, reference `faixas_dono.regua_noite`), with the stays of the night's
  kind: stays starting that night with at least `market.night_min_competitors` values; else stays
  that include the night; else the nearest stays up to `market.neighbour_days` away; else the
  typical value of the kind (`TypeTable`: the median of the monthly medians); else no value. Every
  median is rounded half to even. The screen marks a small sample and an old value.

| Series | Basis | Tags |
|---|---|---|
| `cheapest` | cheapest | every competitor |
| `comparable` | comparable | every competitor |
| `peers_cheapest` | cheapest | `peer` |
| `peers_comparable` | comparable | `peer` |

## 5. The ruler of a run

`MarketRulerAssembler::of()` gives the ladder its ruler for a range of nights: per night its kind,
the stored percentiles of each configured series, the series `market` from the M1 market anchor
(25, 50, 75, 90, 100), the public factor of the property for the kind, and the reference prices of
the active strategy. A night with neither series nor anchor has no ruler night: the ladder does not
price it. Each import also feeds the M1 anchor: every counting comparable value becomes one sample
of each night of its stay, and the anchors of the nights the import touched are rebuilt.

A sales ladder rule may cite only a series the market builds (or `market`) and a percentile the
market keeps: `LadderSeriesCheck` refuses the others when the rules are saved (codes
`ladder.series_unknown`, `ladder.percentile_not_measured`), because a missing point would otherwise
be a silent gap.

## 6. Reminder and gaps

`MarketReminder::check()` gives the full run (and the screen) what the market alerts need:

- **stale**: no price newer than `market.stale_after_days` covers a night of the next
  `market.reminder_horizon_days` (`MarketFreshness`). The pace always reads the market anchors, so
  this applies to every property. The screen shows "Market prices were last updated 9 days ago.
  Import or type this week's prices." with the import link.
- **gaps**: the nights of the next 30 that an enabled ladder strategy would price but whose cited
  points have no value on the ruler (`MarketGaps`: the opening and the ceiling of the rules of the
  night's kind, and the last-minute floor inside its window, not on an excluded weekday nor on a
  holiday it skips). The screen lists them with the missing points in words ("Comparable rooms,
  percentile 50").

Raising `market_stale` (warning, one open alert per property, notified at most once every 7 days,
resolved by the first import that covers the horizon) and `market_gap` (info, one per run, resolved
when a run finds none) is for the integration: the alert codes live in the shared domain.

## 7. Configuration (section `market`)

`MarketFields::all()` describes every field and `MarketConfig::fromArray()` reads it with neutral
defaults. Editing the section (Rules › General › Market) makes a new rule-set version and never
writes a price.

| Key | Neutral default | Parity |
|---|---|---|
| `market.series` | the four series of section 4 | same |
| `market.percentiles` | 10, 25, 40, 50, 65, 75, 90 | same |
| `market.min_competitors_per_stay` | 10 | 15 |
| `market.night_min_competitors` | 20 | 40 |
| `market.neighbour_days` | 3 | 3 |
| `market.max_sample_age_days` | 30 | 30 |
| `market.retention_days` | 400 | 400 |
| `market.comparable.default_factor` | 1.0 | 1.395 |
| `market.comparable.estimated_factor_cap` | 2.0 | 2.0 |
| `market.small_sample.stays`, `.competitors` | 4, 40 | 4, 40 |
| `market.stale_after_days` | 7 | 7 |
| `market.reminder_horizon_days` | 60 | 60 |
| `market.csv.max_bytes`, `.max_rows`, `.max_stay_nights` | 2 MB, 20000, 7 | same |
| `market.bands` | Fill 25, Market 50, Above market 65, Premium 75, Top 90 | same |

## 8. The Market screen

Tabs: **Market by night** (30 nights a page: per configured series the middle and the usual range
in money, where the value came from in words, competitors, age, the badges "old" and "small
sample", the market anchor; the reminder banner and the gap list above), **Competitors** (list with
the factor in words: "× 1.4 (measured)", "× 2 (estimated, capped)", "× 1 (not set: default)"; add,
edit, archive), **Prices** (filters by competitor, nights and source, 100 a page; "Add a price"),
**Import** (the two steps of section 3; the last 10 imports with their counts) and **Export**.
`ritmo_view` sees and exports; `ritmo_manage_rules` changes.

**Exports** (`ritmo_market_export`, POST with nonce, CSV through `CsvWriter`: UTF-8 with BOM, comma,
dot decimal, ISO dates, every text cell starting with `=`, `+`, `-`, `@`, a tab or a carriage return
gets an apostrophe):

| Kind | Columns |
|---|---|
| ruler | `night, night_type, series, percentile, value, currency, competitors, stays, origin, distance_days, newest_capture, oldest_capture` |
| samples | `competitor_code, check_in, check_out, room, price, price_basis, total, currency, captured_at, source, available` |
| competitors | `code, name, reference, room_label, tags, comparable_factor, factor_source, rating, active, own_listing` |

### Form actions (for the integration)

| Action | Capability | Nonce per object |
|---|---|---|
| `ritmo_competitor_save`, `ritmo_competitors_create` | manage rules | |
| `ritmo_competitor_archive` | manage rules | `competitor` |
| `ritmo_sample_add` | manage rules | |
| `ritmo_market_csv_preview` (upload), `ritmo_market_csv_import`, `ritmo_market_csv_cancel` | manage rules | |
| `ritmo_market_export`, `ritmo_market_template` | view (downloads) | |

`MarketScreen::screens()` registers the screen (`Menu::MARKET`, assets `editors.css` and
`editors.js`); `MarketActions::actions()` the actions. The uninstaller removes the transients
`ritmo_csv_*` and `ritmo_csv_last_*`.

## 9. REST

| Route | Capability | Answer |
|---|---|---|
| `GET /ritmo/v1/market/competitors?property=` | view | the competitors with the factor in words |
| `POST /ritmo/v1/market/competitors` | manage rules | 201 and the new id |
| `PUT /ritmo/v1/market/competitors/{id}` | manage rules | the fields sent replace the stored ones; `active: false` alone archives |
| `POST /ritmo/v1/market/import/preview` (multipart, field `file`) | manage rules | the token, counts and problems in words; nothing stored |
| `POST /ritmo/v1/market/import/commit` (`token`) | manage rules | counts and a sentence; 409 when the preview expired or was used |
| `GET /ritmo/v1/market/ruler?property=&from=&to=` | view | per night the series, the anchor, age and flags; at most 120 nights |
| `GET /ritmo/v1/market/imports?property=` | view | the last 10 imports with counts (never a cell) |

Every route checks the capability and the REST nonce before anything is looked up
(`RestRoutes::allowed`), declares its inputs with a type and a pattern, and answers errors as
`{code: "ritmo_<reason>", message}` with a translated sentence: 400 bad input, 404 unknown property
or competitor (after the capability), 409 already imported or preview expired. CSV downloads stay
form actions.

## 10. Parity

`tests/fixtures/tools/golden_market.py` runs the pure functions of the reference engine
(`faixas_dono`: `quantil`, `r_hidro`, `estadia_por_noite`, `construir`, `mediana_por_mes`,
`regua_noite`, `percentil_de`, `posicao`) on frozen invented inputs (no bytecode, no network, no
read of the engine's data folder) and writes `tests/fixtures/golden/m4/market/` (214 cases);
running it again gives the same files. `MarketGoldenTest` compares: money within 0.01, percentiles,
counts and origins exact.

### Deliberate divergences

| # | Plugin | Reference | Effect on parity |
|---|---|---|---|
| MK-X1 | The newest price per competitor, stay and basis counts. | The first sweep file's version of a whole stay wins. | The golden test drops the repeated stay of the second file. |
| MK-X2 | The night rule of section 4 for every series. | `regua_noite` only for the comparable series; the analysis built the others with another fallback. | None: the ladder parity reads its own frozen ruler. |
| MK-X3 | Night kinds are the rule set's calendar labels. | Hard-coded seasons and festive nights. | The golden forces the kind of each stay. |
| MK-X4 | Only future stays and prices within `market.max_sample_age_days`. | Whatever the sweep files hold. | None (the frozen sweep is in the future). |
| MK-X5 | A measured comparable row beats the derived value. | Always derived. | None (the golden has no comparable rows). |
| MK-X6 | Rows in another currency are refused. | One currency. | None. |

## 11. Tests

- PHPUnit: `tests/Unit/Domain/Market/Csv` (the reader, every row code, the byte checks),
  `tests/Unit/Domain/Market/Series` (goldens, the series rules, the ladder check, the gaps),
  `tests/Unit/Application/Market` (competitors, import in two steps, series cache, ruler, exports,
  freshness and reminder, over SQLite), `tests/Unit/Admin/Market` (the upload reader with real
  files: valid, BOM, semicolon, Windows-1252, NUL byte, PHP, HTML, zip, PDF, oversized, forged; every
  text), `tests/Integration/Rest/Editors/MarketEndpointsTest.php` (the REST answers).
- Playwright (`tests/e2e/m4-editors.js`): a competitor added; a file with good and bad lines
  previewed and imported; the prices listed; the three exports downloaded with no cell starting like
  a formula; the REST routes answer 401 without the nonce and 404 for an unknown property.

## 12. Not in this milestone

- The screen does not yet say where the property's own public price sits ("at the 46th percentile
  of comparable rooms, between Market and Above market"): `PricePosition` and its goldens are ready,
  the screen part is not.
- "Your price now vs the median" per room needs the stored pricing calendar of the run
  (`pricing_calendar`, admin spec 12.3), which the integration adds.
- A comparable factor measured from the property's own listing rows (MK-D3) is for later; the ruler
  uses the configured factor.
