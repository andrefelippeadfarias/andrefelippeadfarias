# Ritmo Revenue

WordPress plugin for revenue management of small hotels and inns: it reads bookings and inventory from the PMS, prices from the dynamic pricing system and market samples from the owner, and decides the floor and ceiling of each room night with a fixed, auditable rule engine. Every rule is configurable from the admin.

Requires WordPress 6.5+ and PHP 8.1+. No Composer runtime dependencies.

**Status: version 1.0.0 (milestone M5, the release).** Every property starts in shadow mode (it computes and shows what it would do and writes nothing); a person can move it to approval (writes only what a person approved) and, when the site allows it and the licence is valid, to autonomous. Admin menu "Ritmo Revenue" with thirteen screens: Overview, Nights, Approvals, Alerts, To-do, Change log, Reports, Market, Orders, Rules, Operations, Assistant, Settings (setup wizard, connections, notifications, licence, diagnostics, uninstall). English and Brazilian Portuguese. REST API `ritmo/v1`. Installed from a zip (`bin/build.sh`). A user guide in Portuguese: `docs/GUIA-DO-USUARIO.md`.

What milestone 5 delivers:

- **The optional assistant** (off by default; the customer's own Claude API key, plain HTTP, no SDK): the weekly panel and a run report in words, an alert explained, a request in plain words turned into a **draft** a person opens in the order form or the rules draft and saves (or not). It never writes a price, an order in force, a rule version, a mode or a safety limit; guest data is never sent; a monthly cap of the estimated spend switches it off. `docs/AI.md`.
- **Licence**: a key checked against the licence server embedded in the plugin, whose "valid" answers must be signed with its Ed25519 key (when it is saved, from Settings > Licence and once a day; never inside a run), 30 days of grace, then shadow mode only: the next run puts a property in approval or autonomous mode back in shadow mode and the assistant switches off. Shadow mode always works. `docs/LICENCE-UPDATE.md`.
- **Signed updates** through WordPress' own updater, from `RITMO_UPDATE_URL`: the metadata and the package are signed (Ed25519) and checked before WordPress installs anything (`bin/sign-release.php`).
- **The package**: `bin/build.sh` makes `dist/ritmo-revenue-<version>.zip` (translations compiled, versions agreeing, PHP 8.1 syntax, nothing of the owner's properties, no secret) and `bin/e2e-zip.sh` installs it on a clean site, uses it and deletes it with nothing left. `readme.txt`, `CHANGELOG.md`.

What milestone 4 delivered, all configurable with neutral, conservative defaults:

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
docs/               PLANO.md (product plan, internal: not in the zip), IMPLANTACAO.md (deployment on the owner's site, internal: not in the zip), GUIA-DO-USUARIO.md (user guide, Portuguese), ARCHITECTURE.md, CONTRACTS.md, ENGINE.md, INFRASTRUCTURE.md, CONNECTORS.md, APPLICATION.md, and per area RELATIONS.md, LADDER.md, ORDERS.md, STAY.md, VALIDATOR.md, WRITE.md, VERIFY.md, CHANGELOG-UNDO.md, OPERATIONS.md, ALERTS.md, ADMIN.md, EDITORS.md, MARKET.md, REPORTS.md, AI.md, LICENCE-UPDATE.md
languages/          the .pot, the pt_BR translation (.po, .mo, .l10n.php) and its glossary
tests/              PHPUnit suites, fakes and fixtures
bin/                developer scripts (not in the zip)
readme.txt          the plugin's readme in the WordPress format; CHANGELOG.md the changes of each version
```

## Development

### Tests

```
bin/test.sh                      the lints, then the whole suite (downloads PHPUnit to tools/ on first use)
php bin/lint.php                 the plugin's own lint (php -l, escaping, request reads, REST permissions, form actions, external loads)
php bin/lint-php81.php           syntax and functions newer than PHP 8.1 in the files that ship (readonly classes, DNF types...)
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

`bin/wp-test-site.sh` downloads WordPress and the SQLite plugin from wordpress.org into `.wp-test/` (outside git), installs the site with `tests/e2e/install.php` and serves it with `php -S`, next to the local simulator of the PMS and pricing APIs and of the Claude API (`tests/fake-apis/`, the latter under `/anthropic`). `bin/e2e.sh` (Node and Playwright must be installed) runs the setup wizard on a fresh site, the passes of M1 to M3 on a seeded site (a shadow run sends only reads; in approval mode nothing leaves before the approval and never a price or a booking goes to the PMS; the emergency switch stops every write), the M4 screens, editors, market and reports on a new seeded site, every screen in Portuguese without an English sentence of the plugin, `tests/e2e/wordpress-checks.php`, the update of a site built with the M3 plugin, the assistant against the fake Claude API (`tests/e2e/m5-ai.js`), the licence and the signed updates against a local licence and update server (`tests/e2e/m5-licence.js`) and the zip (`bin/e2e-zip.sh`); it fails on any PHP error of the plugin in `.wp-test/debug.log`. Screenshots of every screen in English and Portuguese, at 1280 and 390 px, go to `tests/e2e/screenshots/`.

### The package

```
bin/build.sh [output folder]     dist/ritmo-revenue-<version>.zip and its .sha256, after every check
bin/e2e-zip.sh [zip] [shots]     the zip installed on a clean site, used and deleted (builds one without a zip)
php bin/sign-release.php         key pair, signing and checking a release for the update channel
```

`bin/build.sh` compiles the translations, checks that the version agrees in the header, `RITMO_VERSION`, `readme.txt` and the changelogs, that every text is translated, stages the files `.distignore` keeps, runs `php -l` and `bin/lint-php81.php` on them, scans them for the owner's own names, secrets and stray file types, zips them in `ritmo-revenue/` and reads the zip back. The same commit gives the same sha256.

### Constants for wp-config.php

- `RITMO_SECRET_KEY`: key of the secrets vault, 32 characters or more. Recommended: without it the key is derived from a seed in the database bound to `AUTH_KEY` and `SECURE_AUTH_KEY` (regenerating those salts makes the saved keys unreadable), and Diagnostics says which protection is in use. A shorter value disables the vault with a notice, without breaking the site.
- `RITMO_KILL_SWITCH`: the emergency switch, which the screen cannot release: nothing is written; scheduled runs only read and watch.
- `RITMO_PRICING_CALENDAR_READ`: the pricing system's calendar read (a POST that only reads) is on by default; define the constant as `false` to switch it off and read prices from the PMS only (see `docs/ARCHITECTURE.md` 6).
- The licence server and the public key that signs its answers are embedded in the plugin; until a licensing platform is chosen they are empty and every site is unlicensed: shadow mode only, no assistant. `RITMO_LICENCE_GRACE_DAYS` (1 to 90, default 30) sets the grace period.
- `RITMO_UPDATE_URL` and `RITMO_UPDATE_PUBLIC_KEY`: the update channel (signed metadata) and the publisher's public key(s); empty, there is no update channel.
- `RITMO_DEV` and the base URL constants (`RITMO_BEDS24_BASE_URL`, `RITMO_PRICELABS_BASE_URL`, `RITMO_ANTHROPIC_BASE_URL`) are for development only.

Read `docs/ARCHITECTURE.md` before adding code: it says what each folder may do, who owns it and the conventions for dates, rounding, errors and logging. `docs/CONTRACTS.md` has the exact signatures shared between modules.

The rule engine is a port of a reference engine; `tests/fixtures/golden` holds its results for frozen inputs and the suite requires the PHP port to match them. `tests/fixtures/tools` regenerates those fixtures.
