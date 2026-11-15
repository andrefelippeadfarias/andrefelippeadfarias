# Changelog

All notable changes to Ritmo Revenue. Versions follow [semantic versioning](https://semver.org/): a new major version may need a step from you, a minor version adds features, a patch fixes bugs.

## [1.0.0] - 2026-11-15

The first release.

### Safety

- Every property starts in **shadow mode**: the plugin reads, decides and shows what it would do, and writes nothing. A person moves it to **approval mode** (only what a person approved is written) and, when the site allows it, to **autonomous mode** (after enough days in approval and plans written).
- Only the write gate writes. Today's price never goes up; no price goes below the absolute floor of its room; the PMS never receives a price or a booking; the minimum, base and maximum of a listing change only with an explicit human approval by someone who may change the safety limits.
- Writes go in series, in small parts, stop at the first failure and are read back; a write cut in the middle is never repeated blindly. A night someone else changed by hand is frozen. A wave of cancellations holds price cuts until a person releases it.
- Emergency switch on the Operations screen and `RITMO_KILL_SWITCH` in wp-config.php.
- Change log with before, after, who and when, and undo.

### Engine

- Pace-based floors and ceilings by room and night, occupancy and floors, market anchors, room relations, price bands, the sales ladder, sold-out protection, minimum stay and arrival/departure rules, owner orders and a full validator, ported from a reference engine and checked against it by golden tests.
- Scheduler on WP-Cron (full and light runs, no overlap, retries), alerts with e-mail for the critical ones, recalculation quota of the pricing system.

### Admin

- Setup wizard: connections, property, rooms, rules preset, safety limits, a first shadow run and "what the plugin would do".
- Overview, nights grid with the reason of every number, approvals, alerts, to-do, change log, reports, market, orders, rules (versions, review in words, relations, calendar with Brazilian and Portuguese holidays), operations, settings (connections, diagnostics, support file without keys or guest data, uninstall choice).
- Market prices of competitors by CSV or typed in; reports with CSV downloads protected against formulas.
- Capabilities per task (`ritmo_view`, `ritmo_manage_rules`, `ritmo_approve`, `ritmo_manage_connections`, `ritmo_manage_safety`, and `ritmo_use_ai` for the assistant: manager and administrator), the Revenue Manager and Revenue Viewer roles; nonce and capability on every form and REST route.
- English and Brazilian Portuguese.

### Optional

- AI assistant with your own Claude API key: report texts, explanations of alerts and drafts of orders or rule changes from a request in your words. It never writes a price; a draft waits for a person and goes through the validator. No e-mail, phone or document is sent and requests that look like they name a guest are refused; the text of a request is never kept. Monthly spending cap, with the cost of every call reserved before it is made.
- Licence (shadow mode always works; the writing modes and the assistant need an active licence or its grace period; only answers signed by the licence server count) and updates signed by the publisher.

### Package

- `bin/build.sh` builds `dist/ritmo-revenue-<version>.zip` with its SHA-256, checked for PHP 8.1 compatibility, secrets, names of real properties and a size under the 2 MB default upload limit (the package holds the user guide, not the developer documentation or the translation sources); `bin/e2e-zip.sh` installs it on a clean WordPress with that limit through Plugins > Add New > Upload Plugin, runs the wizard and deletes it, checking that the uninstall removes everything of the plugin and nothing else.
- Multisite: activate it site by site; network activation is refused.
