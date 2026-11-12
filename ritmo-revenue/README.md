# Ritmo Revenue

WordPress plugin for revenue management of small hotels and inns: it reads bookings and inventory from the PMS, prices from the dynamic pricing system and market samples from the owner, and decides the floor and ceiling of each room night with a fixed, auditable rule engine. Every rule is configurable from the admin.

Requires WordPress 6.5+ and PHP 8.1+. No Composer runtime dependencies.

**Status: milestone M1, read-only.** The plugin computes and shows what it would do (shadow mode) and writes nothing to any external system. Admin menu "Ritmo Revenue": Dashboard, Connections, Setup, Diagnostics; REST API `ritmo/v1`.

## Layout

```
ritmo-revenue.php   plugin entry point
src/                Ritmo\ (PSR-4): Domain (pure PHP engine), Contracts, Connectors, Infrastructure, Application, WordPress, Admin, Rest
templates/ assets/  server-rendered screens, plain CSS and JavaScript (no build step)
presets/            default.json, the neutral preset
docs/               PLANO.md (product plan), ARCHITECTURE.md, CONTRACTS.md, ENGINE.md, INFRASTRUCTURE.md, CONNECTORS.md, APPLICATION.md
tests/              PHPUnit suites, fakes and fixtures
bin/                developer scripts
```

## Development

```
bin/test.sh                      run the whole suite (downloads PHPUnit to tools/ on first use)
bin/test.sh tests/Unit/Domain    run one folder or file
bin/test.sh NumTest              run by filter
php bin/sync-generated.php       rewrite presets/default.json and the signature references of the docs
bin/e2e.sh [screenshot folder]   end-to-end test on a throwaway WordPress with SQLite (Node + Playwright)
bin/wp-test-site.sh start|stop   the throwaway WordPress alone: http://127.0.0.1:8090, simulator on :8091
```

Constants for wp-config.php: `RITMO_SECRET_KEY` (key of the secrets vault), `RITMO_KILL_SWITCH` (stops every run), `RITMO_PRICING_CALENDAR_READ` (allows the pricing system's calendar read, a POST; off by default). `RITMO_DEV` and the base URL constants are for development only.

Read `docs/ARCHITECTURE.md` before adding code: it says what each folder may do, who owns it and the conventions for dates, rounding, errors and logging. `docs/CONTRACTS.md` has the exact signatures shared between modules.

The rule engine is a port of a reference engine; `tests/fixtures/golden` holds its results for frozen inputs and the suite requires the PHP port to match them. `tests/fixtures/tools` regenerates those fixtures.
