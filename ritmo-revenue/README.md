# Ritmo Revenue

WordPress plugin for revenue management of small hotels and inns: it reads bookings and inventory from the PMS, prices from the dynamic pricing system and market samples from the owner, and decides the floor and ceiling of each room night with a fixed, auditable rule engine. Every rule is configurable from the admin.

Requires WordPress 6.5+ and PHP 8.1+. No Composer runtime dependencies.

**Status: milestone M2, read-only.** The plugin computes and shows what it would do (shadow mode) and writes nothing to any external system. Admin menu "Ritmo Revenue": Dashboard, Connections, Setup, Diagnostics; REST API `ritmo/v1`.

What milestone 2 delivers, all configurable with neutral defaults:

- **The whole plan of a run** (`Domain\Plan\Assembly\PlanAssembler`): pace step with owner orders, sales ramp and last-minute market floor; protection of sold-out nights; sales ladder strategies; relations between rooms (at least, between, premium over the dearest, discount band, occupancy switch) with cycles refused when saved; the listing maximum in chain; the delete-first plan for ceilings to free, with stay and arrival rules (orphan nights, closed and open days, packages); the no-effect filter; one step per night per day (cadence and step ledger).
- **The full validator** (safety limit, tonight never rises, stale plan and stale calendar, floors by family, owner orders and their releases, stay and masks, orphan proofs, discount bands, weekly listing limit, items without effect), **the payload and its slices** (at most 70 dates per call; deletions, tonight, tomorrow, then blocks, the listing last). Data only: nothing sends them.
- **The shadow run** assembles the plan, stores every proposal with its outcome, reason and validator issues, and keeps the payload and slices; the dashboard shows, per room night, the price now, the proposal, why (reason, order, relations, rule trace) and the validator's errors.
- **Parity** with the reference engine: golden tests per area and ten whole runs (level 2: plan, validator, payload, slices).

## Layout

```
ritmo-revenue.php   plugin entry point
src/                Ritmo\ (PSR-4): Domain (pure PHP engine), Contracts, Connectors, Infrastructure, Application, WordPress, Admin, Rest
templates/ assets/  server-rendered screens, plain CSS and JavaScript (no build step)
presets/            default.json, the neutral preset
docs/               PLANO.md (product plan, internal: not in the zip), ARCHITECTURE.md, CONTRACTS.md, ENGINE.md, INFRASTRUCTURE.md, CONNECTORS.md, APPLICATION.md, and per area RELATIONS.md, LADDER.md, ORDERS.md, STAY.md, VALIDATOR.md
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

`bin/wp-test-site.sh` downloads WordPress and the SQLite plugin from wordpress.org into `.wp-test/` (outside git), installs the site with `tests/e2e/install.php` and serves it with `php -S`, next to the local simulator of the PMS and pricing APIs (`tests/fake-apis/`). `bin/e2e.sh` seeds an invented property (`tests/e2e/seed.php`), drives the screens with Playwright (`tests/e2e/shadow-run.js`, Node and Playwright must be installed), checks that only reads reached the simulator, runs `tests/e2e/wordpress-checks.php` (emergency switch across processes, keys at rest, capabilities after a new activation, a `RITMO_SECRET_KEY` that is too short) and fails on any PHP error of the plugin in `.wp-test/debug.log`.

### Constants for wp-config.php

- `RITMO_SECRET_KEY`: key of the secrets vault, 32 characters or more. Recommended: without it the key is derived from a seed in the database bound to `AUTH_KEY` and `SECURE_AUTH_KEY` (regenerating those salts makes the saved keys unreadable), and Diagnostics says which protection is in use. A shorter value disables the vault with a notice, without breaking the site.
- `RITMO_KILL_SWITCH`: stops every run.
- `RITMO_PRICING_CALENDAR_READ`: the pricing system's calendar read (a POST that only reads) is on by default; define the constant as `false` to switch it off and read prices from the PMS only (see `docs/ARCHITECTURE.md` 6).
- `RITMO_DEV` and the base URL constants are for development only.

Read `docs/ARCHITECTURE.md` before adding code: it says what each folder may do, who owns it and the conventions for dates, rounding, errors and logging. `docs/CONTRACTS.md` has the exact signatures shared between modules.

The rule engine is a port of a reference engine; `tests/fixtures/golden` holds its results for frozen inputs and the suite requires the PHP port to match them. `tests/fixtures/tools` regenerates those fixtures.
