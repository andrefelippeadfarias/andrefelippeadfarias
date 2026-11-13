# Ritmo Revenue

WordPress plugin for revenue management of small hotels and inns: it reads bookings and inventory from the PMS, prices from the dynamic pricing system and market samples from the owner, and decides the floor and ceiling of each room night with a fixed, auditable rule engine. Every rule is configurable from the admin.

Requires WordPress 6.5+ and PHP 8.1+. No Composer runtime dependencies.

**Status: milestone M4, the complete admin.** Every property starts in shadow mode (it computes and shows what it would do and writes nothing); a person can move it to approval (writes only what a person approved) and, when the site allows it, to autonomous. Admin menu "Ritmo Revenue" with twelve screens: Overview, Nights, Approvals, Alerts, To-do, Change log, Reports, Market, Orders, Rules, Operations, Settings (connections, setup wizard, notifications, diagnostics, uninstall). English and Brazilian Portuguese. REST API `ritmo/v1`. A user guide in Portuguese: `docs/GUIA-DO-USUARIO.md`.

What milestone 4 delivers, all configurable with neutral, conservative defaults:

- **The screens**: a setup wizard that ends with what the plugin would do; the nights grid with the reason of every number in words; approvals by room and run of nights, with amounts before and after; alerts, to-do, change log with undo, operations; the rules editor (draft per person, review of the changes in words, a new version of the rules on save, never a price written by a rule change), room relations in sentences, the calendar with national holidays, owner orders (copy and end, never edit).
- **Market**: competitor prices by CSV (preview, invalid lines listed, nothing kept of the file) or typed; the price series the sales ladder reads; reminders when prices get old.
- **Reports**: weekly panel, month and goals, channels, next 12 months, targets and forecast, the report of every run in the reader's language; CSV exports protected against formulas.
- **Stay writes**: minimum stay and arrival/departure written to the PMS (and to the pricing system when its push carries them), sealed with the plan and sent through the same write gate (off by default).
- **Safety of the admin**: capabilities per task (`ritmo_manage_safety` only for administrators), nonce and capability on every form and route, typed confirmation for safety limits, the plugin's own lint (`bin/lint.php`).
- Earlier milestones: safe writing, scheduler and alerts (M3), the rule engine and the whole plan of a run (M2), the connectors, storage, secrets and the shadow run (M1).

## Layout

```
ritmo-revenue.php   plugin entry point
src/                Ritmo\ (PSR-4): Domain (pure PHP engine), Contracts, Connectors, Infrastructure, Application, WordPress, Admin, Rest
templates/ assets/  server-rendered screens, plain CSS and JavaScript (no build step)
presets/            default.json, the neutral preset
docs/               PLANO.md (product plan, internal: not in the zip), GUIA-DO-USUARIO.md (user guide, Portuguese), ARCHITECTURE.md, CONTRACTS.md, ENGINE.md, INFRASTRUCTURE.md, CONNECTORS.md, APPLICATION.md, and per area RELATIONS.md, LADDER.md, ORDERS.md, STAY.md, VALIDATOR.md, WRITE.md, VERIFY.md, CHANGELOG-UNDO.md, OPERATIONS.md, ALERTS.md, ADMIN.md, EDITORS.md, MARKET.md, REPORTS.md
languages/          the .pot, the pt_BR translation (.po, .mo, .l10n.php) and its glossary
tests/              PHPUnit suites, fakes and fixtures
bin/                developer scripts
```

## Development

### Tests

```
bin/test.sh                      the lint, then the whole suite (downloads PHPUnit to tools/ on first use)
php bin/lint.php                 the plugin's own lint (php -l, escaping, request reads, REST permissions, form actions, external loads)
php bin/make-pot.php             languages/ritmo-revenue.pot from the code (--check: is it current?)
php bin/compile-l10n.php         the .mo and .l10n.php from languages/ritmo-revenue-pt_BR.po (--check)
bin/test.sh tests/Unit/Domain    run one folder or file
bin/test.sh NumTest              run by filter
bin/test.sh tests/Golden         only the golden tests against the reference engine
php bin/sync-generated.php       rewrite presets/default.json and the signature references of the docs
```

PHPUnit comes as a .phar from phar.phpunit.de (kept in `tools/`, outside git, only when its sha256 matches a pinned release); nothing else is needed for the suite, which runs on SQLite in memory and never calls a real API. After changing a public signature, run `php bin/sync-generated.php`: the doc tests fail while a signature reference is stale.

### Test site and end-to-end test

```
bin/e2e.sh [screenshot folder]   the whole end-to-end test (default folder: tests/e2e/screenshots)
bin/wp-test-site.sh start        the throwaway WordPress alone: http://127.0.0.1:8090, simulator on :8091
bin/wp-test-site.sh stop         stop both servers
```

`bin/wp-test-site.sh` downloads WordPress and the SQLite plugin from wordpress.org into `.wp-test/` (outside git), installs the site with `tests/e2e/install.php` and serves it with `php -S`, next to the local simulator of the PMS and pricing APIs (`tests/fake-apis/`). `bin/e2e.sh` (Node and Playwright must be installed) runs the setup wizard on a fresh site, the passes of M1 to M3 on a seeded site (a shadow run sends only reads; in approval mode nothing leaves before the approval and never a price or a booking goes to the PMS; the emergency switch stops every write), the M4 screens, editors, market and reports on a new seeded site, every screen in Portuguese without an English sentence of the plugin, `tests/e2e/wordpress-checks.php`, and the update of a site built with the M3 plugin; it fails on any PHP error of the plugin in `.wp-test/debug.log`. Screenshots of every screen in English and Portuguese, at 1280 and 390 px, go to `tests/e2e/screenshots/`.

### Constants for wp-config.php

- `RITMO_SECRET_KEY`: key of the secrets vault, 32 characters or more. Recommended: without it the key is derived from a seed in the database bound to `AUTH_KEY` and `SECURE_AUTH_KEY` (regenerating those salts makes the saved keys unreadable), and Diagnostics says which protection is in use. A shorter value disables the vault with a notice, without breaking the site.
- `RITMO_KILL_SWITCH`: the emergency switch, which the screen cannot release: nothing is written; scheduled runs only read and watch.
- `RITMO_PRICING_CALENDAR_READ`: the pricing system's calendar read (a POST that only reads) is on by default; define the constant as `false` to switch it off and read prices from the PMS only (see `docs/ARCHITECTURE.md` 6).
- `RITMO_DEV` and the base URL constants are for development only.

Read `docs/ARCHITECTURE.md` before adding code: it says what each folder may do, who owns it and the conventions for dates, rounding, errors and logging. `docs/CONTRACTS.md` has the exact signatures shared between modules.

The rule engine is a port of a reference engine; `tests/fixtures/golden` holds its results for frozen inputs and the suite requires the PHP port to match them. `tests/fixtures/tools` regenerates those fixtures.
