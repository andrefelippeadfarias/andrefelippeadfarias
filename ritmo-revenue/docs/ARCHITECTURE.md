# Ritmo Revenue: architecture

The product plan is in [PLANO.md](PLANO.md). The exact signatures of everything shared between modules are in [CONTRACTS.md](CONTRACTS.md); the rule engine (calendar, market, pricing, plan, report) is in [ENGINE.md](ENGINE.md). This document says where code goes, what each folder may and may not do, who owns it, and the conventions every module follows.

## 1. What the plugin is

A WordPress plugin that manages nightly prices for small hotels and inns. It reads bookings and inventory from a PMS, prices and overrides from a dynamic pricing system and market samples from the customer, then decides, with a fixed and auditable rule engine, what the floor and ceiling of each room night should be.

The rule engine is a port of a Python engine that already runs in production. Parity with it is proven by golden tests (section 7).

**Milestone M1 is read-only.** The plugin computes and shows what it would write ("shadow mode") and writes nothing to any external system. Section 6 lists the guards that make this true by construction.

## 2. Module map

```
ritmo-revenue.php        plugin header, constants, autoloader, hooks Plugin::boot()
uninstall.php            uninstall handler
src/
  Plugin.php             composition root: wires modules to WordPress hooks
  Domain/                the rule engine. PURE PHP.
    Support/             Clock, Num, Days, DateRange
    Model/               immutable DTOs and enums shared by every module
    Config/              Schema, RuleSetValidator, RuleSet, PresetLoader
    Calendar/ Market/ Pricing/ Relations/ Orders/ Plan/ Alerts/ Report/   engine modules
  Contracts/             interfaces between the engine and the outside world. PURE PHP.
  Connectors/            Beds24/, PriceLabs/, Market/, Licence/, Ai/ (implement Contracts)
  Infrastructure/        Db/, Http/, Secrets/, Scheduler/, Lock/, Log/ (WordPress-facing plumbing)
  Application/           use cases: the run pipeline, later the write gate, undo, approval
  Admin/  Rest/          wp-admin screens and the REST API ritmo/v1
templates/ assets/       server-rendered views, plain JS and CSS (no build step)
languages/               translations (text domain ritmo-revenue)
presets/default.json     the neutral preset shipped with the plugin (generated)
bin/                     developer scripts (test.sh, sync-generated.php)
docs/                    this folder
tests/                   Unit/ Golden/ Integration/ e2e/ Fakes/ Support/ fixtures/
tools/                   downloaded developer tools (PHPUnit .phar); outside git
```

### What each folder may and may not do

| Folder | May | May not |
|---|---|---|
| `src/Domain/**` | Pure computation over DTOs and the rule set. Depend on `Domain/**` only. | Call or mention anything from WordPress. Touch the network, the database, files (except `PresetLoader::fromFile`), globals or the system clock. Round with PHP's `round()`, `ceil()`, `floor()` or `number_format()` outside `Support/Num.php`. Read time outside `Support/SystemClock.php`. |
| `src/Contracts/**` | Declare interfaces, their small value objects and exceptions. Depend on `Domain/Model` and `Domain/Support`. | Contain behaviour beyond trivial value objects. Mention WordPress. |
| `src/Connectors/**` | Implement `Contracts` by talking to one external system through `Contracts\HttpClient`. Map external payloads to `Domain\Model` DTOs. | Call the WordPress HTTP API or cURL directly. Keep guest names or contacts. Decide prices. Send a write in M1. |
| `src/Infrastructure/**` | Use WordPress (`$wpdb`, options, HTTP API, cron/Action Scheduler, sodium). Implement `HttpClient`, `KeyValueStore`, `QuotaLedger`, `Logger`, repositories, migrations, the secrets vault. | Contain pricing rules. Be called from `Domain`. |
| `src/Application/**` | Orchestrate: load configuration, call connectors, call the engine, store and report the result. | Contain pricing rules or SQL. Bypass the validator or the write guards. |
| `src/Admin/**`, `src/Rest/**`, `templates/**`, `assets/**` | Render screens, register REST routes, check capabilities and nonces, escape output, translate texts. | Contain pricing rules, call external systems directly, write SQL (use repositories). |
| `tests/**` | Anything needed to test. Owner-specific data lives only under `tests/fixtures/`. | Call a real external API. Tests use fakes on `127.0.0.1` or in-memory doubles. |

Dependency direction (an arrow means "may depend on"):

```
Admin, Rest ─▶ Application ─▶ Domain ◀─ Contracts ◀─ Connectors
                    │                        ▲            │
                    └──▶ Contracts ──────────┘            ▼
Infrastructure ─▶ Contracts, Domain\Model          Contracts\HttpClient
```

`Domain` depends on nothing. `Contracts` depends only on `Domain\Model` and `Domain\Support`. Nothing depends on `Admin` or `Rest`.

The architecture tests in `tests/Unit/Domain/ArchitectureTest.php` enforce the pure-PHP rule, the clock rule, the rounding rule, `declare(strict_types=1)`, PSR-4 naming and the absence of owner-specific data in plugin code. They fail the build; do not relax them.

### Ownership

Each folder has one owner per phase, so that parallel work never edits the same file.

| Folder | Owner |
|---|---|
| `src/Domain/Support`, `src/Domain/Model`, `src/Domain/Config`, `src/Contracts`, `tests/Support`, `tests/fixtures/tools`, `tests/fixtures/frozen`, `docs/CONTRACTS.md`, `docs/ARCHITECTURE.md`, `bin/` | **Foundation.** After this phase these are changed only by the integration phase, or by another phase when it needs a new rule field, DTO field or golden file (see "Extending the foundation" below). |
| `src/Domain/Calendar`, `Market`, `Pricing`, `Plan`, `Report` and their tests under `tests/Unit/Domain/<Module>` and `tests/Golden` | **Engine** phase |
| `src/Infrastructure/**`, `tests/Unit/Infrastructure`, `tests/Integration/Infrastructure` | **Infrastructure** phase |
| `src/Connectors/**`, `tests/Fakes/**` (fake servers and in-memory connectors), `tests/Unit/Connectors`, `tests/Integration/Connectors` | **Connectors** phase |
| `src/Application/**`, `tests/Unit/Application` | **Application** phase |
| `src/Admin/**`, `src/Rest/**`, `templates/**`, `assets/**`, their tests | **Panel** phase |
| `ritmo-revenue.php`, `src/Plugin.php`, `uninstall.php`, `README.md`, `tests/phpunit.xml`, `tests/bootstrap.php` | **Integration** phase (wiring, whole-suite run) |

### Extending the foundation

- **A new rule field:** add a `Field` in `src/Domain/Config/RuleSetFields.php` (with range, neutral default, label and help), a typed accessor in `RuleSet`, the cross-field rule in `RuleSetValidator` if there is one, the mapping in `tests/fixtures/tools/make_parity_preset.py` when the reference engine has the value, then run `php bin/sync-generated.php` and `python3 tests/fixtures/tools/make_parity_preset.py`.
- **A new DTO or DTO field, a new contract method:** change the code, then run `php bin/sync-generated.php` (it rewrites the signature reference of `CONTRACTS.md`). New constructor parameters go at the end with a default, so existing callers keep working.
- **A new golden file:** add the cases to `tests/fixtures/tools/dump_golden.py` and run it. Never edit a golden JSON by hand.

## 3. Conventions

### Language and style

- PHP 8.1+, `declare(strict_types=1)` in every file, namespace root `Ritmo\`, PSR-4 under `src/`. No Composer runtime dependencies (`src/autoload.php` is the autoloader).
- Code, identifiers and comments in English. Classes are `final` unless designed for extension.
- No dead code, no vague TODO, no giant functions.

### Dates and time

- A **date-only value** (a night, an arrival, "today") is a `DateTimeImmutable` at 00:00:00 UTC. Build it with `Days::date('2026-11-11')` or `Days::normalize()`. Never give a date a local timezone.
- An **instant** (created at, pushed at) is a `DateTimeImmutable` in UTC.
- A **night** is identified by its check-in date. Ranges of nights are inclusive at both ends (`DateRange`, and every `$from`/`$to` in `Contracts`). A stay from the 10th to the 12th covers the nights 10 and 11.
- Weekdays follow Python: Monday = 0 ... Sunday = 6 (`Days::weekday()`).
- "Today" comes from `Clock::today($property->timezone)`. `D`, the days until a night, is `Days::diff($today, $night)`.
- The domain receives a `Clock`; tests use `FixedClock`.

### Money and rounding

- Amounts are floats in the property currency.
- Every amount lives in a **price plan**: `sent` (what the pricing system sends to the PMS), `public` (what a guest sees on the channel) or `discounted` (the lowest price after the channel's deepest standing discount). `RuleSet::priceFactor()` gives plan / sent.
- All rounding goes through `Num`: `roundHalfEven()` is Python's `round()`; `ceilEps()`/`floorEps()` forgive float noise; a floor as a percentage over the listing base rounds **up** (`floorToPercent`), a ceiling **down** (`ceilingToPercent`).
- Keep the order of float operations of the reference engine when porting a formula: the golden tests compare exact values.

### Identifiers and vocabulary

- Property ids and room type codes are user-chosen codes (`Identifier::CODE_PATTERN`), unique per site. Tiers and tags are lowercase slugs (`Identifier::SLUG_PATTERN`).
- The reference engine is written in Portuguese. The mapping is fixed:

| Reference engine | Plugin |
|---|---|
| `semana`, `fds`, `feriado` | `NightType::Weekday`, `Weekend`, `Holiday` |
| `frente`, `ritmo`, `atras`, `muito_atras`, `frente_perto` | `PaceStatus::Ahead`, `OnPace`, `Behind`, `VeryBehind`; `ahead_near` |
| `pousada` | property (`Property::$id`), and its `tier` for the target percentiles |
| `quarto` / `curto` | room type / `RoomType::$code` |
| `enviado`, `público`, `vitrine` (most discounted) | `PricePlan::Sent`, `PublicRate`, `Discounted` |
| `piso`, `teto`, `passo`, `curva`, `banda`, `âncora` | floor, ceiling, step, curve, band, anchor |
| `min_referencia`, `unidades` | `RoomType::$referenceMin`, `$units` |

### Errors

- **Domain:** throw, never return `false` or an error array. A bad argument is an `\InvalidArgumentException`. A rejected configuration is a `Domain\Config\InvalidConfig` carrying every `ValidationError` (path, code, message). A lookup that cannot succeed is an `\OutOfBoundsException`. `WP_Error` does not exist in `Domain` or `Contracts`.
- **Connectors:** every failure talking to an external system is a `Contracts\Exception\ConnectorException` (`provider()`, `httpStatus()`, `isRetryable()`), with `RateLimited` (stop the batch, do not loop) and `AuthFailed` (never retried) as special cases. `testConnection()` never throws.
- **Bugs and blocks are not failures to retry:** `NotAvailableInThisMilestone` (a `LogicException`) and `WriteBlocked` do not extend `ConnectorException`. Nobody catches them to continue; they end the run and are recorded.
- **Application:** catches per step, records the failure in the run and stops that property's run; other properties continue.
- **REST and admin:** convert exceptions at the edge: `InvalidConfig` becomes HTTP 400 with the list of errors; `ConnectorException` becomes 502; anything else 500 with a generic message. Exception messages are English sentences safe to show: they never contain a credential, a request with headers or guest data.
- Messages of exceptions and validation errors are plain English strings created in the domain; the admin layer may translate known messages when it shows them.

### Logging

- Everything logs through `Contracts\Logger` (levels `debug`, `info`, `warning`, `error`).
- The message is a fixed English sentence; variable data goes in the context array with snake_case keys. Usual keys: `run_id`, `property_id`, `room_code`, `night`, `provider`, `http_status`, `duration_ms`, `attempt`.
- **Never log:** API keys, tokens, authorisation headers, whole request or response bodies that contain bookings, or any guest data (names, contacts, addresses, free-text notes).
- `debug` is off by default. `warning` means "the run continued but a person should look"; `error` means "the step failed".

### Texts and translation

- Interface texts are English with the text domain `ritmo-revenue` and the WordPress translation functions, in `Admin`, `Rest` and `templates` only.
- `Domain` texts (schema labels and help, validation messages) are plain English strings because `Domain` cannot call WordPress. The admin layer translates them when rendering; the strings file for extraction is generated from the schema in the milestone that adds `pt_BR`.

### WordPress-facing code

- Every admin action checks a capability and a nonce. Every REST route has a `permission_callback` that checks a capability.
- Every value printed in a view is escaped at output. Every query with a variable uses `$wpdb->prepare`.
- API keys are stored encrypted in the secrets vault and are write-only in forms.
- Tables are prefixed `{$wpdb->prefix}ritmo_`. Options are prefixed `ritmo_`.

## 4. Configuration model

- `Domain\Config\Schema::ruleSet()` is the single definition of every configurable rule: dotted key, type, range, neutral default, label, help. The validator, the generated forms and import/export read it.
- A **rule set** is one validated array (`RuleSet`). It is site-wide; where properties differ it holds a table per property (price factors, with `*` as the fallback entry) or per tier (target percentiles; each property has a `tier`).
- A **preset** is a file with properties, their room types and a rule set (`PresetLoader`, `Preset`). `presets/default.json` is the neutral preset: no property, schema defaults. `tests/fixtures/presets/parity.json` is the reference engine's configuration, used only by tests.
- A rule set is immutable. `RuleSet::hash()` identifies a version.
- Importing a preset must never switch a property to `auto` mode on its own: the import use case sets every imported property to `shadow`; leaving shadow is always an explicit action of a person in the admin.

## 5. Running the tests

```
bin/test.sh                          whole suite
bin/test.sh tests/Unit/Domain        one folder or file
bin/test.sh NumTest                  a filter
```

`bin/test.sh` downloads PHPUnit (a `.phar` in `tools/`, outside git) from phar.phpunit.de on first use and exits with PHPUnit's code. The suite treats warnings, notices, deprecations and risky tests as failures.

- `tests/Unit/**` mirrors `src/**`. Pure, no WordPress.
- `tests/Golden/**` holds the parity tests of the engine against the golden fixtures.
- `tests/Integration/**` needs a WordPress or a fake server on `127.0.0.1`.
- `tests/Fakes/**` holds fake servers and in-memory implementations of `Contracts`.
- `tests/Support/**` holds helpers: `Fixtures` (paths, the parity preset), `Golden` (golden cases and vocabulary), `SourceScan`, `SignaturePrinter`, `ContractsDoc`.

During a phase, run only your own folder; the integration phase runs the whole suite.

## 6. Read-only guarantees of M1

No code path may send POST, PUT, PATCH or DELETE to a PMS or pricing system. Three independent guards:

1. **Contracts:** every write method of `PmsConnector` and `PricingConnector` throws `NotAvailableInThisMilestone`. Implementations get this from the traits `Contracts\Support\PmsWritesUnavailable` and `PricingWritesUnavailable`; they do not write their own.
2. **Write token:** every write method requires a `Contracts\WriteToken`, whose constructor is private and which has no factory until the milestone that adds the write gate. Nobody can call a write method without forging one by reflection.
3. **HTTP:** the `HttpClient` implementation refuses mutating methods with `WriteBlocked` before anything leaves the site.

The end-to-end check of M1 asserts that the fake servers received no POST and no DELETE during a shadow run.

**Open point for the connectors and infrastructure phases:** the verified API notes of the pricing system list its calendar read as `POST /listing_prices` (a read that uses POST). Guard 3 as specified forbids it. The options are: (a) the run pipeline takes the current price and the units sold of each night from the PMS (`PmsConnector::readInventory(..., withPrices: true)` gives `InventoryNight::$pmsPrice`, the price the pricing system sent; bookings give the units sold) and never calls `readPrices()` of a connector whose only price read is a POST (a call ends in `WriteBlocked` from the HTTP guard); or (b) that single read-only endpoint is put on an explicit allow-list in the HTTP guard (method + host + exact path), covered by a test. Until the owner chooses (b), (a) is the rule: no POST leaves the site. This is a product-safety decision and must be made explicitly, not worked around.

## 7. Golden tests

The reference engine's pure functions are run with frozen inputs by `tests/fixtures/tools/dump_golden.py`, which writes `tests/fixtures/golden/level1/<module>.<function>.json`:

```json
{"function": "pace.curva", "php_target": "...", "notes": "...",
 "cases": [{"input": {"tipo": "fds", "dias": 7}, "expected": 50.0}]}
```

- Inputs use the reference engine's argument names and vocabulary. `Ritmo\Tests\Support\Golden` loads the cases and translates the vocabulary (`Golden::nightType('fds')`).
- Functions that take the engine configuration are computed in PHP from the parity preset (`Fixtures::parityRules()`, `Fixtures::parityPreset()`).
- Tolerance: amounts within 0.01; percentages and integers exact. NaN is written as the string `"NaN"`.
- `tests/fixtures/golden/MANIFEST.json` records the repository commit at generation (`repo_commit`), the last commit that changed the reference engine (`engine_commit`), whether the engine had uncommitted changes (`engine_dirty`, which must be `false`), the generation date, the hashes of the frozen inputs and the case count of every file. A test fails if a frozen input changes without the goldens being regenerated, or if the goldens came from uncommitted engine code.
- To regenerate after the reference rules change: `python3 tests/fixtures/tools/freeze_inputs.py`, then `dump_golden.py`, then `make_parity_preset.py`. The tools read the reference engine's folder and never write to it.

## 8. What is not here yet

Room relations, stay and arrival rules, the sales ladder, owner orders, dated exceptions to floors, per-room holiday floors and packages belong to M2 and are not in the rule set schema. The parity preset tool lists what it leaves out each time it runs.
