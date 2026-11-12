# Ritmo Revenue

WordPress plugin for revenue management of small hotels and inns: it reads bookings and inventory from the PMS, prices from the dynamic pricing system and market samples from the owner, and decides the floor and ceiling of each room night with a fixed, auditable rule engine. Every rule is configurable from the admin.

Requires WordPress 6.5+ and PHP 8.1+. No Composer runtime dependencies.

**Status: milestone M1, read-only.** The plugin computes and shows what it would do (shadow mode) and writes nothing to any external system.

## Layout

```
ritmo-revenue.php   plugin entry point
src/                Ritmo\ (PSR-4): Domain (pure PHP engine), Contracts, Connectors, Infrastructure, Application, Admin, Rest
presets/            default.json, the neutral preset
docs/               PLANO.md (product plan), ARCHITECTURE.md, CONTRACTS.md
tests/              PHPUnit suites, fakes and fixtures
bin/                developer scripts
```

## Development

```
bin/test.sh                      run the whole suite (downloads PHPUnit to tools/ on first use)
bin/test.sh tests/Unit/Domain    run one folder or file
bin/test.sh NumTest              run by filter
php bin/sync-generated.php       rewrite presets/default.json and the signature reference of docs/CONTRACTS.md
```

Read `docs/ARCHITECTURE.md` before adding code: it says what each folder may do, who owns it and the conventions for dates, rounding, errors and logging. `docs/CONTRACTS.md` has the exact signatures shared between modules.

The rule engine is a port of a reference engine; `tests/fixtures/golden` holds its results for frozen inputs and the suite requires the PHP port to match them. `tests/fixtures/tools` regenerates those fixtures.
