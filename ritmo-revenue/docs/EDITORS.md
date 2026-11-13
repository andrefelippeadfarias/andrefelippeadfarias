# Ritmo Revenue: the rule editors and the owner orders (milestone M4)

This document covers the Rules screen (the editor of every rule of the schema, its safety tab, room
relations and price bands, the calendar with national holidays, the versions) and the Orders screen
(owner orders and frozen nights). The Market screen and its data are in `MARKET.md`; the central
screens and the shared kit (`src/Admin/Components`) in `ADMIN.md`.

An editor never writes a price and never calls an external system. Saving rules makes a new,
immutable version of the rule set (checked by `RuleSetValidator`, recorded in the change log with
who and when) that applies from the next run; an order is data the next run reads. Every write to a
pricing system or a PMS still goes through the run pipeline and the write gate of M3.

## 1. Where each piece is

| Folder | Classes | What they do |
|---|---|---|
| `src/Application/Rules` | `RuleSetEditor`, `SavedVersion`, `RuleDiff`, `FieldChange`, `RuleMerge`, `RuleDraft`, `RuleDraftStorage`, `RuleChecks`, `SafetyFields`, `Percent`, `PrivacyCheck`, `VersionHistory`, `RulesRefused`, `PendingApprovals`, `ApprovalServiceAdapter` | The one way to change the rules: draft, review, version; the three-way merge; safety fields and their typed confirmation; the history and the comparison of versions. No WordPress. |
| `src/Application/Rules/Calendar` | `Easter`, `HolidayCatalogue`, `HolidayList`, `HolidayProposal`, `HolidayProposals` | National holiday lists shipped with the plugin and the periods they propose (section 6). Pure; no network. |
| `src/Application/Rules/Orders` | `OrderDesk`, `OrderForm`, `OrderBoard` | Create, copy and end owner orders; release frozen nights; the lists of the Orders screen. |
| `src/Admin/Editors` | `EditorScreens` (registry), `EditorServices`, `EditorPage`, `RulesScreen`, `RulesLayout`, `Placement`, `FormScope`, `FieldView`, `FieldWidget`, `FieldContext`, `FieldPath`, `ValueText`, `ChoiceLabels`, `RuleFormReader`, `FormResult`, `FormErrors`, `RequestFields`, `DraftDesk`, `UserMetaDraftStorage`, `ReviewView`, `VersionsView`, `PresetFile`, `RulesActions`, `RelationsView`, `RelationForm`, `RelationText`, `RelationCycle`, `RelationActions`, `CalendarView`, `CalendarActions`, `OrdersScreen`, `OrdersActions`, `OrderText`, `EditorText` | WordPress side: what each tab renders, the form fields built from the schema, the handlers of the forms, every text in words. |
| `src/Rest/Editors` | `EditorRoutes`, `EditorEndpoints` | Two read-only routes: the versions and the comparison of two versions (section 9). |
| `src/Infrastructure/Db/Repository` | `EditorEventRepository`, `OrderLedgerRepository` | The `rules_version`, `order`, `competitor` and `market_import` events in `change_log`; the orders as rows with their end. |
| `templates/admin/editors` | `frame`, `rules-section`, `field`, `draft-bar`, `review`, `versions`, `relations`, `band`, `calendar`, `orders` | Markup only; every value escaped. |
| `assets/editors.css`, `assets/editors.js` | | Styles (focus ring, reduced motion, cards below 600 px) and progressive enhancement: the typed confirmation enables its button, the error summary takes the focus, the changes of a version open in place. Every action works without the script. |
| `presets/holidays/BR.json`, `PT.json` | | The national holiday lists (section 6). |

## 2. Registration (for the integration)

The areas never edit the shared files; the integration registers what follows.

- **Screens** (`EditorScreens::screens()`): `Menu::ORDERS` ("Orders", tabs In force, Frozen nights,
  Ended, New order) and `Menu::RULES` ("Rules", tabs General, Safety limits, Room relations, Calendar, Versions,
  Review and save). Both need `ritmo_view` to open; their assets are `editors.css` and `editors.js`
  (`EditorScreens::ASSETS`).
- **Form actions** (`EditorScreens::actions()`, dispatched like `ScreenActions::handle`: POST only,
  capability, nonce, then the handler):

| Action | Capability | Nonce per object | Effect |
|---|---|---|---|
| `ritmo_rules_draft_update` | manage rules | | the posted section into the person's draft |
| `ritmo_rules_draft_discard` | manage rules | | drops the draft |
| `ritmo_rules_save` | manage rules (safety fields: also manage safety, typed) | | version N+1 |
| `ritmo_rules_keep_theirs` | manage rules | | drops a conflicting key from the draft |
| `ritmo_rules_restore` | manage rules | `version` | loads a version into the draft and opens the review |
| `ritmo_rules_export` | view (download) | | the preset file of the rules in force |
| `ritmo_rules_import` | manage rules | | a preset file into the draft (replaces Setup's `ritmo_import_preset`) |
| `ritmo_relation_stage`, `ritmo_band_stage` | manage rules | | a relation or a price band into the draft |
| `ritmo_calendar_stage`, `ritmo_holidays_stage` | manage rules | | weekend nights, a period, a season, a package, or ticked holiday proposals into the draft |
| `ritmo_order_create` | manage rules (safety releases: also manage safety, typed property id) | | a new order |
| `ritmo_order_end` | manage rules | `order` | ends an order now |
| `ritmo_order_freeze_release` | approve | `freeze` | releases frozen nights from the Orders screen |

  The Nights screen releases a freeze through its own `ritmo_freeze_release` (central screens); the
  Orders screen's action has its own name so the two handlers never collide. The integration may
  keep one handler for both by letting it return to the screen that asked.
- **REST**: `EditorRoutes::register()` on `rest_api_init`.
- **Uninstall**: user meta `ritmo_rules_draft`.
- **Change log kinds**: `rules_version` and `order` are written by `EditorEventRepository` into
  `change_log` (`provider` empty, `field` = the kind); `OperationEventRepository` may list them on
  Operations/events.

## 3. One way to change the rules: draft, review, version

Every change of the rule set (a section of the General tab, the safety tab, a relation, a band, the
calendar, a restored version, an imported preset file) goes the same way:

1. **Draft.** `DraftDesk` keeps, per person, the candidate rules and the version and hash they
   started from (`RuleDraft`, user meta `ritmo_rules_draft`, dropped after 7 days, on save or on
   discard). A section form posts only that section's fields; `RuleFormReader::read()` reads them
   strictly against the schema: a value that does not read is shown again with its error and is not
   kept; a value that reads is kept even when a rule across fields fails (that is checked at the
   review). Every tab shows the bar "You have unsaved changes to N settings · Review and save ·
   Discard" while a draft exists.
2. **Review.** `RuleDiff::between()` lists one row per value that changed (list items matched by
   their id when they have one, else by position) with the label path ("Occupancy › Occupancy goals
   › Next 7 days"), before, after and the default, in words and units, never as JSON. Then the full
   check: the schema, `RuleSetValidator`, the relations with the rooms of the site and the
   references to unknown rooms and properties (`RuleChecks`). Errors link to their field. Safety
   fields that changed are listed with the typed confirmation they need; a plan waiting for approval
   is named, because saving closes it.
3. **Save.** `RuleSetEditor::save()` checks everything again, refuses a safety change without
   `ritmo_manage_safety` or with a wrong typed value, merges with a newer version in force
   (section 3.1), stores version N+1 (equal rules keep the current number: "Nothing changed"),
   records the `rules_version` event (from, to, number of changes, note, source, the safety
   confirmation), expires the plans waiting for approval with the reason "the rules changed", and
   clears the draft. Saving never starts a run and never calls a connector.

### 3.1 Two people editing

The draft records the version it started from. When the version in force is newer at save time,
`RuleMerge::merge()` (pure, per leaf key) applies the keys only the draft changed on top of the
rules in force; a key changed both in the draft and in the newer version is a conflict, listed in
the review with "Keep version N's value", and the save is refused until no conflict is left.

### 3.2 Versions

The Versions tab lists 20 versions per page with when, who, where from (rules screen, setup,
restore, preset file), the note and how many values changed; the changes open in place (script and
the read-only compare route) or through the comparison form (no script). "Restore" loads a version
into the draft and opens the review: the restore is a new version with the same content and hash,
the history is never rewritten. "Export a preset file" downloads the rules in force in the existing
preset format (`ritmo-revenue-preset`, no key, no booking); "Import a preset file" (JSON, at most
1 MB, read in place, sniffed for binary or code) loads it into the draft.

## 4. Where each field is edited and how

`RulesLayout::place()` gives every schema key its tab and section by prefix, outside the schema; a
key that matches nothing throws, and a test places every field of `Schema::ruleSet()` exactly once,
so a new schema field fails until it is placed. Sections of the General tab: Goals, Pace, Floors and
price plans, Sales ladder, Market, Sold-out nights, cadence, small changes, Stay and arrival/departure,
Owner order defaults, Plan and writing, Runs and alerts. Relations and price bands, and calendar,
seasons and packages, have their own tabs; the safety keys theirs. Some keys sit under "Advanced
settings" of their section (`RulesLayout::advanced()`).

`FieldWidget` picks the control from the field: numbers with their range, step and unit; shares
(unit ratio) typed **as a percent** and converted by moving the decimal point in the text
(`Percent::toRatio("70")` is exactly `0.7`, never a float product); checkboxes (locked fields
disabled with "Fixed by the product"); choices as a select with a text per option (`ChoiceLabels`,
a test fails on a missing text); weekdays as seven checkboxes; room codes as room checkboxes; lists
of values as repeated inputs; lists of groups as repeatable fieldsets with Remove. Next to every
field: its help, the allowed range, the default and a "Changed" badge. A dotted key is never shown:
`FieldPath::label()` turns `floors.owner.1.amount` into labels and item names, and
`FieldPath::anchor()` gives the id the error summary links to.

## 5. Room relations and price bands

The Room relations tab shows the order in which rooms are priced (from `RelationGraph`), then one
card per relation with a sentence (`RelationText::describe()`, the five kinds of `RELATIONS.md`),
its nights, priority and on/off; then the price bands. Adding a relation starts by choosing what it
should do (five choices, each a sentence), then the form built from `RelationFields`. Add, edit,
duplicate, turn on or off and delete stage the change into the draft.

The relations are checked at once with the rooms of the site (`RelationValidator`). A loop is
refused next to the form with the walk in words (`RelationCycle::find()` and `RelationText::loop()`):
"This would make a loop: Room A depends on Room C, Room C depends on Room B and Room B depends on
Room A (relations …). Turn one of them off or change it." Nothing of a refused relation is kept.

## 6. Calendar and national holidays

The Calendar tab edits the weekend nights (seven checkboxes; default Friday and Saturday, named by
the check-in day), shows three months as tables (caption with the month, a column per weekday, the
marks of each night as text: weekend night, period name, season and its minimum stay, package),
and lists the holiday periods (first period covering a night wins; overlaps are flagged), the
seasons and the packages.

**Import national holidays** proposes periods from a list shipped with the plugin, without network:

- `presets/holidays/<country>.json` (`ritmo-revenue-holidays`, version 1): the country names, the
  days off of the week, whether bridge days are usual, and each holiday with an id, its names and a
  rule: `fixed` (`MM-DD`) or `easter` (days from Gregorian Easter Sunday, `Easter::sunday()`,
  anonymous Gregorian algorithm). Optional days off (federal "ponto facultativo") carry
  `kind: optional`.
- `HolidayProposals::forYears()`: each holiday date; its block of days off extended over adjacent
  weekend days off and, with bridges, the Monday before a Tuesday holiday and the Friday after a
  Thursday holiday; touching blocks merged (names joined with " + "); the proposed nights run from
  the evening before the first day off to the night before the last one. A block that is only a
  weekend is proposed unticked ("falls on a weekend"); a proposal covering a night already in a
  period is shown "already in your list" and unticked. Ticked proposals become `calendar.holidays`
  items appended to the draft.

| File | Holidays | Source |
|---|---|---|
| `BR.json` | New Year, Carnival Monday and Tuesday (optional), Good Friday, Tiradentes, Labour Day, Corpus Christi (optional), Independence, Our Lady Aparecida, All Souls, Republic, Black Consciousness, Christmas | Lei 662/1949 with Lei 10.607/2002 (fixed national holidays), Lei 6.802/1980 (12 October), Lei 14.759/2023 (20 November), Lei 9.093/1995 (Good Friday as a religious holiday), and the yearly federal ordinance on holidays and optional days off of the federal administration (Carnival, Corpus Christi). |
| `PT.json` | New Year, Carnival Tuesday (optional), Good Friday, Easter, Freedom Day, Labour Day, Corpus Christi, Portugal Day, Assumption, Republic, All Saints, Restoration of Independence, Immaculate Conception, Christmas | Código do Trabalho, articles 234 and 235 (Lei n.º 7/2009, as amended by Lei n.º 8/2016). |

Each file carries `checked`: the dates published for 2026 to 2028 (Brazil) and 2026 (Portugal); a
test computes every holiday of 2025 to 2035 from the rules and compares the checked years with
these published dates, and the spec examples (Brazil 2027: Carnival nights 5–8 February, Corpus
Christi nights 26–29 May) are tests too. Other countries, and rules such as "nth weekday" or
"observed on Monday", are for a later version.

## 7. Owner orders and frozen nights

Orders (`ORDERS.md`) are data, not rules: they are not versioned with the rule set, they apply from
the next run, and they are never edited or deleted. The Orders screen lists the orders in force,
the frozen nights and the ended orders (last 180 days), each with a sentence of what it does
(`OrderText::describe()`) and what it allows. "New order" starts from six choices ("Leave these
nights alone", "Minimum price", "Market target", "Standing permission", "Stay or arrival exception",
"Reminder only"); "Copy" opens the form filled in and may end the original on save; "End" ends an
order now. `OrderDesk::create()` reads the form (`OrderForm`), checks the note with `PrivacyCheck`,
validates the row with `OrderReader` and records the `order` event. A release of a safety limit
(listing, below the standard floors, above the weekly rise limit) needs `ritmo_manage_safety` and
the property id typed. Releasing frozen nights needs `ritmo_approve` and the checkbox "I understand
the plugin may change these nights again from the next run".

## 8. Security

- Capability first, then the nonce, then the fields: the dispatcher checks the action's capability
  and nonce; the use case checks again what needs more (`ritmo_manage_safety` for safety fields and
  safety releases) and refuses the whole action otherwise.
- Destructive actions on one object (restore a version, end an order, release a freeze) use a nonce
  per object (`<action>:<id>`).
- Typed confirmations are the new value (safety fields) or the property id (safety releases), never
  a translated word; the server compares after trimming and records what was confirmed.
- Form input is read in two places only: `Admin\Components\Input` for scalars and
  `RequestFields::posted()` for the nested arrays of the schema forms (keys filtered by pattern,
  values through `sanitize_textarea_field`, depth bounded); `PresetFile::upload()` is the only
  reader of an uploaded preset file.
- Free text typed by a person (version notes, order notes) goes through `PrivacyCheck`: an e-mail,
  a phone number or a CPF/CNPJ is refused with "Do not write guest names, e-mails, phone numbers or
  document numbers here. Refer to a booking by its id."
- Every value printed by a template is escaped; GET forms (filters, holiday preview, comparison)
  change nothing and carry no nonce.

`tests/Unit/Admin/Editors/EditorsSourceTest.php` checks these rules on the source.

## 9. REST

| Route | Capability | Answer |
|---|---|---|
| `GET /ritmo/v1/rules/versions?page=&property=` | view | the versions of a page: number, saved at, by, note, number of changes, in force |
| `GET /ritmo/v1/rules/compare?from=&to=&property=` | view | the changes in words (`label`, `before`, `after`) and a sentence; 404 with a sentence for an unknown version |

Both check the capability and the REST nonce before anything is looked up (`RestRoutes::allowed`).
The property only chooses the currency money values are written in.

## 10. Tests

- PHPUnit: `tests/Unit/Application/Rules` (editor, merge, diff, percent, privacy, safety fields,
  holidays, orders), `tests/Unit/Admin/Editors` (placement of every field, every choice has a text,
  labels of paths, values and relations in words, the form reader, the relation loop, the source
  rules), `tests/Integration/Rest/Editors` (the REST answers over SQLite).
- Playwright: `tests/e2e/m4-editors.js` (a goal changed and saved as a version, its change opened
  in place; a safety limit with the typed value; a relation and its loop refused; national holidays
  imported; an order given and ended; the market flow of `MARKET.md`; every screen at 1280 and
  390 px with the cross-screen checks).

## 11. Differences from the admin spec

- The holiday classes live in `Application\Rules\Calendar` (pure, but next to the editor that uses
  them) instead of `Domain\Calendar`.
- The preset import of the Versions tab is `ritmo_rules_import` (the M3 action
  `ritmo_import_preset` of Setup stays until the integration moves it), and the Orders screen
  releases a freeze with `ritmo_order_freeze_release` (section 2).
- The nested fields of the schema forms are read by `RequestFields`, next to `Input` (which reads
  scalars only).
