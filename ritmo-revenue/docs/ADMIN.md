# Ritmo Revenue: the central admin screens (milestone M4)

This document covers the menu and the central screens of the admin: setup wizard, overview, nights
grid and night detail, approvals, alerts, change log and undo, to-do, connections and diagnostics.
The rule editors, the market screens and the reports have their own documents. The write path the
screens hand work to is in `WRITE.md`, `VERIFY.md` and `CHANGELOG-UNDO.md`; the alerts in
`ALERTS.md`; the scheduler in `OPERATIONS.md`.

A screen never writes to an external system. It reads through read models, and every change it
asks for goes through a use case of the application layer (approval desk, undo service, alert
service, setup wizard), which hands any write to the write gate of M3.

## 1. Where each piece is

| Folder | Classes | What they do |
|---|---|---|
| `src/Admin/Components` | `Menu`, `Tab`, `Screen`, `Capability`, `FormAction`, `Input`, `ConfirmTyped`, `Ratio`, `Money`, `Percent`, `PriceText`, `NightDate`, `Labels`, `Paging`, `PrivacyGuard`, `ErrorText`, `UserFacing`, `CsvWriter` | The menu and its redirects, form actions, input reading, the formats of money, percent and nights, the words of every code, the guest-data guard, error texts with a reference, CSV with the formula guard. |
| `src/Admin/Screens` | `Screens`, `ScreenServices`, `Page`, `ScreenActions`, one `*Screen` per menu entry (`OverviewScreen`, `NightsScreen`, `ApprovalsScreen`, `AlertsScreen`, `TodoScreen`, `ChangesScreen`, `SettingsScreen`), the views (`NightDetailView`, `WizardView`, `ConnectionsView`, `DiagnosticsView`), the actions (`ApprovalActions`, `AlertActions`, `ChangeActions`, `TodoActions`, `NightActions`, `WizardActions`, `ConnectionActions`, `DiagnosticsActions`), the texts (`AlertText`, `ConnectionText`, `NightText`, `TodoText`, `UndoText`, `WarningText`, `PeopleNames`) and `UndoNow` | WordPress side of the screens: what each one renders, the property picker and status strip, and the handlers of its forms. |
| `src/Rest/Screens` | `ScreenRoutes`, `ScreenEndpoints` | The REST routes the screens' script calls (section 5). |
| `src/Application/Dashboard` | `StatusQuery`, `StatusStrip`; `Overview/` `OverviewQuery`, `Revenue`; `Alerts/` `AlertFilter`, `AlertList`; `Changes/` `ChangeFilter`, `ChangeList`, `ChangeGroups`, `UndoRequests`; `Todo/` `TodoItem`, `TodoStore`, `TodoService`, `TodoGenerator`; `Connections/` `ConnectionReason`, `ConnectionTests`; `Diagnostics/` `SupportBundle`; `Setup/` `SetupState`, `SafetyValues`, `WizardProgress`, `SetupWizard`, `RoomRowsRefused`, `WouldDo`, `ListingSuggestions` | Read models and small use cases of the overview, alerts, change log, to-do, connections, diagnostics and setup. No WordPress. |
| `src/Application/Grid` | `NightGridQuery`, `NightGrid`, `NightCell`, `NightFilter`, `NightMarks`, `GridContext`, `PlanIndex`, `PlannedNight`, `LastPlan`, `NightDetailQuery`, `NightDetail`; `Approvals/` `ApprovalBoard`, `RequestView`, `EntryRow`, `EntryWarnings`, `Segment`, `Segmenter`, `RelationHints`, `RowBuilder`, `Impact` | The nights grid and night detail; the approval requests as rooms, segments and nights in money. |
| `src/Infrastructure/Db/Query/Screens` | `AlertListReader`, `ChangeLogReader`, `TodoRepository` | Paged, prepared reads of alerts and changes; the to-do store. |
| `src/Infrastructure/Db/Migration/DraftScreens.php` | | Draft migration: the to-do columns of `pending_items` (number set at integration). |
| `templates/admin/screens` | `layout`, one template per screen and settings tab, `wizard/*`, `parts/*` | Markup only; every value escaped. |
| `assets/screens.css`, `assets/screens.js` | | Styles (contrast noted next to each colour) and progressive enhancement; every screen works without the script. |

## 2. Menu and navigation

One top-level entry, "Ritmo Revenue", with twelve entries in this order (`Menu::entries()`):

| Entry | Slug | Capability | Tabs |
|---|---|---|---|
| Overview | `ritmo-revenue` | view | |
| Nights | `ritmo-revenue-nights` | view | |
| Approvals | `ritmo-revenue-approvals` | view | Waiting, History |
| Alerts | `ritmo-revenue-alerts` | view | |
| To-do | `ritmo-revenue-todo` | view | |
| Change log | `ritmo-revenue-changes` | view | |
| Reports | `ritmo-revenue-reports` | view | (reports area) |
| Market | `ritmo-revenue-market` | view | (market area) |
| Orders | `ritmo-revenue-orders` | view | (editors area) |
| Rules | `ritmo-revenue-rules` | view | (editors area) |
| Operations | `ritmo-revenue-operations` | view | (M3 screen) |
| Settings | `ritmo-revenue-settings` | view | Setup (manage rules), Connections, Notifications, Diagnostics (view), Uninstall (manage connections) |

- Each area hands `Menu::register()` the renderer of its slug; a slug without one shows a short
  note. Counters (waiting approvals, active alerts, due to-do items) use the WordPress counter
  markup with a screen-reader text, hidden at zero.
- The old slugs `ritmo-revenue-connections`, `-setup` and `-diagnostics` are hidden pages that
  redirect on `load-{hook}` to Settings with the right tab, keeping `property`.
- `Page::open()` checks the capability of the entry and of the tab (403 otherwise), resolves the
  property (URL, then the user's last choice in user meta `ritmo_property`, then the first
  property, with a notice) and prints the header: one `h1`, the property picker (a form, no
  script needed), the status strip (mode, last run, next run, waiting approvals, active alerts)
  and the emergency banner when writing is switched off.
- A heading of every screen takes focus after a form redirect, so a screen reader starts at the
  result notice.

## 3. Screens

### 3.1 Setup wizard (Settings, Setup)

Nine steps derived from facts, never from a stored step number (`WizardProgress`): welcome, PMS
key, pricing key, property, rooms, rules, safety limits, shadow run, "what it would do". A step is
open when the one before is done; a finished wizard shows the summary with links back to any
step.

- Keys go to the vault (`ritmo_wizard_save_pms` / `_pricing`) and are tested at once; the field
  never shows a saved key, only "saved".
- Step 4 suggests the currency and step 5 starts with one room row per listing the pricing system
  showed at step 3 (`ListingSuggestions`, kept in the setting `wizard_listings`): name, listing
  id, minimum, base and maximum, a code from the initials of the name and the PMS room id guessed
  from the number at the end of the listing id. Every value stays editable and a notice says to
  check the PMS room id and the units (the PMS cannot list its rooms yet, decision D4).
- Rooms are saved in one transaction; a refused row lists the row and the reason
  (`RoomRowsRefused`) and nothing is saved.
- Rules: the conservative preset, or a preset file (`.json`, at most 1 MB), plus the weekend days.
  A new rule version is created with the note "Setup wizard".
- Safety limits need `ritmo_manage_safety` and the property id typed again
  (`ConfirmTyped`). Percentages are typed as percent and saved as exact ratios (`Ratio`). What was
  confirmed is kept with a fingerprint of the values (`SetupState`, `SafetyValues`), so a later
  change of a limit asks for a new confirmation.
- The shadow-run button uses the existing `ritmo_run_shadow` action and REST route of M2 (the
  progress section is driven by `assets/admin.js`). Step 8 can be opened again to run once more.
- Step 9 (`WouldDo`) reads the newest completed shadow run even when it planned nothing: it says
  how many room nights were read, how many would change (up, down, the ten nearest with the floor
  before and after) and, when the run had no market price for some nights, that those were left as
  they are, with a link to Market. A site set up only through the wizard has no market prices, so
  its first shadow run changes nothing until prices are added.

### 3.2 Overview

Cards built each in its own `try`: a failing card shows "This card could not be loaded" with a
reference and the rest of the screen still renders. Cards: revenue on the books for the next 7 and
30 nights (or the goal windows), pace, "needs you" (approvals, alerts, to-do), last run and next
runs. All amounts in money; percentages in words.

### 3.3 Nights grid and night detail

- Up to 12 rooms per page and 90 nights per window (`NightGridQuery::window()`), with a bounded
  number of statements whatever the size (test: at most 25 for 12 x 90).
- Each cell names room, night, price, what the last plan would do and why, in its accessible name;
  colour is never the only signal (symbols and words in the cell and in the legend).
- Filters: all, would change, refused, frozen, with an order, with an alert, by pace.
- The detail (`NightDetailQuery`) shows bookings by id and channel only, the freeze with its
  release form (approver, per-object nonce, "I understand" checkbox), the last 50 changes and the
  decisions of the last five runs.

### 3.3.1 Amounts in force without a date rule

A night the run priced without a date rule in force has no floor or ceiling of its own: the
pricing system applies the listing's minimum and maximum. `PlanIndex::withListing()` fills them in
(`PlannedNight::$nowFromListing`), so the approvals, the grid's arrows, the night detail and step 9
show "listing minimum R$ 320 → R$ 433" instead of "— → R$ 433", and the impact counts such nights
as up or down. The screen says where the amount before comes from.

### 3.4 Approvals

`ApprovalBoard` turns a request into rooms, then segments (consecutive nights moving the same
way, with tonight and tomorrow apart and notes for holidays, groups, relations, held floors and
orders), then nights. Amounts before and after in money, the change in percent, the impact of the
selection (nights, up, down, soon). Listing changes are typed again by amount and need
`ritmo_manage_safety`.

- Without the script: checkboxes per night and per segment, "Approve selected", "Approve all",
  "Reject".
- With the script: tri-state segment boxes, the closure of related entries (`PartialApproval`)
  and a live total through `POST /approvals/{id}/selection`.
- After a decision the screen follows the request (`GET /approvals/{id}`) until a final status.
- The statement count of the screen does not grow with the number of entries (test: same count
  for 100 and 2000).

### 3.5 Alerts

Filter by status, severity and group; every alert code has a title, an explanation and an action
in plain words (`AlertText`), and its facts are shown with labels, never as JSON. Acknowledge
(viewer, except security and write alerts), resolve (approver), release a held wave (approver,
confirmation checkbox) and the per-user e-mail opt-out.

### 3.6 Change log and undo

Changes grouped by run, newest first, rows by room, night and field, values in money or words.
Filters by night, date of the change, room, system, who (autonomous run, person, undo) and "not undone". Undo of selected changes
goes through the undo service of M3 (`UndoNow`); what was left out is listed with its reason
(`UndoText`). CSV export with the formula guard.

### 3.7 To-do

Items written by people (refused when they look like guest data) and items the plugin adds
(`TodoGenerator`: confirm safety limits, confirm origins, test a connection, sync now after a
change for tonight or tomorrow). Close and reopen with a per-object nonce.

### 3.8 Connections (Settings, Connections)

Key fields (`ritmo_save_connections` of M1/M2), "Test" per provider with a 30-second cooldown, and
"Forget key" with the provider name typed again. A test result is a sentence for each reason
(`ConnectionReason`, `ConnectionText`): refused key, no permission, not reached, too many
requests, nothing found, no properties, no listings, listings of another PMS, not configured.

### 3.9 Diagnostics (Settings, Diagnostics)

Versions, environment, schedule, last runs and the recent log in plain words; the support file
(`SupportBundle`) is a JSON download with an allowlist of keys. Secret-like settings become
"saved" or "not saved", e-mail addresses are removed, and no guest data is included.

## 4. Form actions (admin-post.php)

Every action is POST only (405 otherwise), checks its capability (403), then its nonce, then reads
the form through `Input`. Destructive actions on one object carry a nonce named after it
(`<action>:<id>`). Errors become a notice: a known refusal shows its text; anything else shows
"Something went wrong. Nothing was changed." with a reference that is logged.

| Action | Capability | Notes |
|---|---|---|
| `ritmo_select_property` | view | remembers the property |
| `ritmo_approval_decide` | approve | per request; reject, selected or all; listing rows need manage safety and the amount typed |
| `ritmo_freeze_release` | approve | per freeze; checkbox |
| `ritmo_alert_acknowledge` | view | per alert |
| `ritmo_alert_resolve` | approve | per alert |
| `ritmo_wave_release` | approve | per property; checkbox |
| `ritmo_alert_email_pref` | view | own preference only |
| `ritmo_undo_request` | approve | selected changes |
| `ritmo_changes_export` | view | CSV download |
| `ritmo_todo_add`, `ritmo_todo_close`, `ritmo_todo_reopen` | manage rules | close and reopen per item |
| `ritmo_wizard_save_pms`, `ritmo_wizard_save_pricing` | manage connections | |
| `ritmo_wizard_save_property`, `_rooms`, `_rules` | manage rules | |
| `ritmo_wizard_confirm_safety` | manage safety | property id typed |
| `ritmo_connection_test`, `ritmo_forget_secret` | manage connections | provider name typed to forget |
| `ritmo_download_support_bundle` | manage connections | JSON download |

## 5. REST routes (`ritmo/v1`)

All with `RestRoutes::allowed()` (X-WP-Nonce plus capability); bodies are translated texts and
codes, never an exception message.

| Route | Method | Capability | Answers |
|---|---|---|---|
| `/approvals/{id}` | GET | view | status, text, final, entries, approved |
| `/approvals/{id}/selection` | POST | approve | closed selection and its impact (400 `ritmo_bad_input`, 404) |
| `/connections/(beds24\|pricelabs)/test` | POST | manage connections | result sentence (429 `ritmo_cooldown`) |
| `/alerts/{id}/acknowledge` | POST | view | 403 `ritmo_forbidden` when not allowed |
| `/alerts/{id}/resolve` | POST | approve | 403 `ritmo_forbidden` when not allowed |

## 6. Rules of the screens

- Output escaped (`esc_html`, `esc_attr`, `esc_url`); every form has a nonce; no inline event
  handlers; nothing loaded from another host.
- Form input is read only through `Input` (the wizard reads `$_FILES` for the preset file, the
  handler reads the request method).
- Money first, then the percent in words; nights printed in UTC; unknown codes show "other",
  never the code.
- Guest data is never shown: bookings by id and channel; free text is checked by `PrivacyGuard`.
- Safety limits need `ritmo_manage_safety` and a typed confirmation.

## 7. Tests

- `tests/Unit/Admin/Screens`: components, texts for every code, source rules.
- `tests/Unit/Application/Grid`, `tests/Unit/Application/Dashboard`: read models over SQLite with
  every migration, statement counts.
- `tests/Integration/Rest/Screens`: the REST answers over SQLite.
- `tests/e2e/m4-screens.js`: Playwright on a private test site and the simulator: menu, redirects,
  connections, shadow run, overview, grid, detail, to-do, approvals end to end with writes to the
  simulator, change log and its export (BOM, formula guard), alerts (severity filter, facts,
  acknowledge), forgetting a key with the wrong name, diagnostics (log, a run's stages), support
  file, nonces, every screen at 1280 and 390 pixels, focus order and rendering without script.
- `node tests/e2e/m4-screens.js wizard` (with `RITMO_E2E_WP`, the WordPress folder): the setup
  wizard on a fresh site before `seed.php` (AC-WIZ-1 to 7): the fresh site opens on step 2, a wrong
  token is refused in words, steps 4 to 8 by keyboard without JavaScript, the suggested rooms, the
  room cards at 390 px, a wrong typed confirmation, step 9 explaining a run without market prices,
  then market prices (`tests/e2e/seed-market.php`) and a second shadow run that would change
  nights in money, with only reads in the simulator log. It needs `ritmo_manage_safety` on the
  administrator (grants version 2 of the integration).
- Known limit: the "Sync Now" to-do item is made from the change log; when the approved write's
  steps for tonight are only "accepted" (not yet confirmed stored) the change log has no row for
  tonight and the item appears only after the next run confirms them. The integration hooks the
  generator to the write stage's steps (admin spec 12.3 item 5).
