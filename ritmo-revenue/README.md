# Ritmo Revenue

WordPress plugin for revenue management of small hotels and inns: it reads bookings and inventory from the PMS, prices from the dynamic pricing system and market samples from the owner, and decides the floor and ceiling of each room night with a fixed, auditable rule engine. Every rule is configurable from the admin.

Requires WordPress 6.5+ and PHP 8.1+. No Composer runtime dependencies.

**Status: milestone M3, safe writing.** Every property starts in shadow mode (it computes and shows what it would do and writes nothing); a person can move it to approval (writes only what a person approved) and, when the site allows it, to autonomous. Admin menu "Ritmo Revenue": Dashboard, Connections, Setup, Operations, Diagnostics; REST API `ritmo/v1`.

What milestone 3 delivers, all configurable with neutral, conservative defaults:

- **The run pipeline** for every kind of run (full, light, "Run now", approved write, shadow): collect, watch (security, below-expected, overbooking, cancellation waves, nights changed by someone else), measure, decide, validate, store the plan, then by mode: nothing (shadow), a plan waiting for approval, or the write; then confirm, record and report.
- **The write path**: one write gate issues one-time tokens only for a fresh validated plan of the property's today, with the emergency switch released, under an approval or the autonomous mode; tokens cannot be forged; writes go in series, slice by slice, stop at the first failure and are read back; after a stop the next run reads again and sends only what is missing; never a price or a booking to the PMS; listing values only with an explicit approval.
- **Confirmation and record**: change log with before, after and the inverse operation, "undo" as a plan that goes through the same path, the step ledger from what was stored, delivery to the channel confirmed by the push and the PMS price.
- **Scheduler** on WP-Cron (five-minute tick, one run lock per property, missed and late slots, light runs scoped to what moved), **alerts** with deduplication and e-mail, **modes** with their conditions, the **emergency switch** on screen and by constant.
- Earlier milestones: the rule engine and the whole plan of a run (M2), the connectors, storage, secrets and the shadow run (M1).

## Layout

```
ritmo-revenue.php   plugin entry point
src/                Ritmo\ (PSR-4): Domain (pure PHP engine), Contracts, Connectors, Infrastructure, Application, WordPress, Admin, Rest
templates/ assets/  server-rendered screens, plain CSS and JavaScript (no build step)
presets/            default.json, the neutral preset
docs/               PLANO.md (product plan, internal: not in the zip), ARCHITECTURE.md, CONTRACTS.md, ENGINE.md, INFRASTRUCTURE.md, CONNECTORS.md, APPLICATION.md, and per area RELATIONS.md, LADDER.md, ORDERS.md, STAY.md, VALIDATOR.md, WRITE.md, VERIFY.md, CHANGELOG-UNDO.md, OPERATIONS.md, ALERTS.md
tests/              PHPUnit suites, fakes and fixtures
bin/                developer scripts
```

## Development

### Tests

```
bin/test.sh                      run the whole suite (downloads PHPUnit to tools/ on first use)
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

`bin/wp-test-site.sh` downloads WordPress and the SQLite plugin from wordpress.org into `.wp-test/` (outside git), installs the site with `tests/e2e/install.php` and serves it with `php -S`, next to the local simulator of the PMS and pricing APIs (`tests/fake-apis/`). `bin/e2e.sh` seeds an invented property (`tests/e2e/seed.php`), drives the screens with Playwright (`tests/e2e/shadow-run.js`, Node and Playwright must be installed), checks that a shadow run sent only reads and that an approval on the Operations screen sends the approved writes (and nothing under the emergency switch), runs `tests/e2e/wordpress-checks.php` (emergency switch across processes, keys at rest, capabilities after a new activation, a `RITMO_SECRET_KEY` that is too short) and fails on any PHP error of the plugin in `.wp-test/debug.log`.

### Constants for wp-config.php

- `RITMO_SECRET_KEY`: key of the secrets vault, 32 characters or more. Recommended: without it the key is derived from a seed in the database bound to `AUTH_KEY` and `SECURE_AUTH_KEY` (regenerating those salts makes the saved keys unreadable), and Diagnostics says which protection is in use. A shorter value disables the vault with a notice, without breaking the site.
- `RITMO_KILL_SWITCH`: the emergency switch, which the screen cannot release: nothing is written; scheduled runs only read and watch.
- `RITMO_PRICING_CALENDAR_READ`: the pricing system's calendar read (a POST that only reads) is on by default; define the constant as `false` to switch it off and read prices from the PMS only (see `docs/ARCHITECTURE.md` 6).
- `RITMO_DEV` and the base URL constants are for development only.

Read `docs/ARCHITECTURE.md` before adding code: it says what each folder may do, who owns it and the conventions for dates, rounding, errors and logging. `docs/CONTRACTS.md` has the exact signatures shared between modules.

The rule engine is a port of a reference engine; `tests/fixtures/golden` holds its results for frozen inputs and the suite requires the PHP port to match them. `tests/fixtures/tools` regenerates those fixtures.
