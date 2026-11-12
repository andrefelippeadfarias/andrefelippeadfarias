# Ritmo Revenue: architecture

The product plan is in [PLANO.md](PLANO.md), an internal document of the project (it names the pilot properties): the vocabulary test skips it and the packaging of M5 leaves it out of the zip. The exact signatures of everything shared between modules are in [CONTRACTS.md](CONTRACTS.md); the rule engine (calendar, market, pricing, plan, report) is in [ENGINE.md](ENGINE.md); the plumbing in [INFRASTRUCTURE.md](INFRASTRUCTURE.md); the connectors in [CONNECTORS.md](CONNECTORS.md); the shadow run, the screens and the REST API in [APPLICATION.md](APPLICATION.md). This document says where code goes, what each folder may and may not do, who owns it, and the conventions every module follows.

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
  Application/           use cases: the shadow run pipeline, the dashboard, the setup; later the write gate, undo, approval
  WordPress/             per-request wiring (Services), activation, deactivation, uninstall, roles, scheduled hook, connector factory
  Admin/  Rest/          wp-admin screens and the REST API ritmo/v1
templates/ assets/       server-rendered views, plain JS and CSS (no build step)
languages/               translations (text domain ritmo-revenue)
presets/default.json     the neutral preset shipped with the plugin (generated)
bin/                     developer scripts (test.sh, sync-generated.php, wp-test-site.sh, e2e.sh)
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
| `src/Application/**` | Orchestrate: load configuration, call connectors (read methods only in M1) through `Application\ConnectorSource`, call the engine, store and report the result through the repositories of `Infrastructure\Db\Repository`, `DbLock` and `Database::transaction()`. | Contain pricing rules or SQL. Call WordPress. Bypass the validator or the write guards. |
| `src/WordPress/**` | Wire the modules for one request (`Services`), run the lifecycle (activation, deactivation, uninstall), install roles and capabilities, schedule hooks, build the connectors from the vault (`ConnectorFactory`). | Contain pricing rules or SQL beyond the uninstall clean-up. Decide anything a use case decides. |
| `src/Admin/**`, `src/Rest/**`, `templates/**`, `assets/**` | Render screens, register REST routes, check capabilities and nonces, escape output, translate texts. | Contain pricing rules, call external systems directly, write SQL (use repositories). |
| `tests/**` | Anything needed to test. Owner-specific data lives only under `tests/fixtures/`. | Call a real external API. Tests use fakes on `127.0.0.1` or in-memory doubles. |

Dependency direction (an arrow means "may depend on"):

```
WordPress ─▶ everything below (it is the composition root)
Admin, Rest ─▶ Application ─▶ Domain ◀─ Contracts ◀─ Connectors
                    │   │                    ▲            │
                    │   └──▶ Contracts ──────┘            ▼
                    └──▶ Infrastructure (repositories, lock, Database::transaction)
Infrastructure ─▶ Contracts, Domain\Model          Contracts\HttpClient
```

`Domain` depends on nothing. `Contracts` depends only on `Domain\Model` and `Domain\Support`. Nothing depends on `Admin`, `Rest` or `WordPress` except the plugin entry files.

The architecture tests in `tests/Unit/Domain/ArchitectureTest.php` enforce the pure-PHP rule, the clock rule, the rounding rule, `declare(strict_types=1)`, PSR-4 naming and the absence of owner-specific data in plugin code. They fail the build; do not relax them.

### Ownership

Each folder has one owner per phase, so that parallel work never edits the same file.

| Folder | Owner |
|---|---|
| `src/Domain/Support`, `src/Domain/Model`, `src/Domain/Config`, `src/Contracts`, `tests/Support`, `tests/fixtures/tools`, `tests/fixtures/frozen`, `docs/CONTRACTS.md`, `docs/ARCHITECTURE.md`, `bin/` | **Foundation.** After this phase these are changed only by the integration phase, or by another phase when it needs a new rule field, DTO field or golden file (see "Extending the foundation" below). |
| `src/Domain/Calendar`, `Market`, `Pricing`, `Plan`, `Report` and their tests under `tests/Unit/Domain/<Module>` and `tests/Golden` | **Engine** phase |
| `src/Infrastructure/**`, `tests/Unit/Infrastructure`, `tests/Integration/Infrastructure` | **Infrastructure** phase |
| `src/Connectors/**`, `tests/Fakes/**` (fake servers and in-memory connectors), `tests/Unit/Connectors`, `tests/Integration/Connectors` | **Connectors** phase |
| `src/Application/**`, `tests/Unit/Application`, `tests/Integration/Application` | **Application** phase |
| `src/Admin/**`, `src/Rest/**`, `templates/**`, `assets/**`, `tests/Unit/WordPress`, `tests/e2e/**` | **Panel** phase |
| `ritmo-revenue.php`, `src/Plugin.php`, `src/WordPress/**`, `uninstall.php`, `README.md`, `tests/phpunit.xml`, `tests/bootstrap.php`, `bin/wp-test-site.sh`, `bin/e2e.sh` | **Integration** phase (wiring, whole-suite run, end-to-end test) |

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

`bin/test.sh` downloads PHPUnit (a `.phar` in `tools/`, outside git) from phar.phpunit.de on first use, keeps it only when its sha256 matches one of the pinned releases listed in the script (there is no unverified fallback: when every pinned download fails, the script stops and asks for a new pinned entry) and exits with PHPUnit's code. A `.phar` already in `tools/` that matches no pinned checksum is downloaded again. The suite treats warnings, notices, deprecations and risky tests as failures.

**Owner vocabulary.** `ArchitectureTest` fails when the names, room codes or ids of the pilot properties (`tests/fixtures/owner-terms.json`) appear in the plugin's code, presets, templates, assets, README or `docs/`. The only exception is `docs/PLANO.md`, marked internal: it stays in the repository and is excluded from the distributed zip by the packaging of M5. Those names may appear only under `tests/` (the parity preset, the frozen inputs and the golden tests built on them).

```
bin/e2e.sh [screenshot folder]       end-to-end test on a throwaway WordPress (needs Node with Playwright and Chromium)
bin/wp-test-site.sh start|restart|stop   the throwaway WordPress alone (.wp-test/, outside git)
```

`bin/e2e.sh` builds WordPress with SQLite from wordpress.org, activates the plugin, seeds the invented property of the simulator (`tests/e2e/seed.php`) and runs `tests/e2e/shadow-run.js` twice (without and with the pricing calendar read), checking the screens, the REST API and the simulator's log. See APPLICATION.md, section 1.6.

- `tests/Unit/**` mirrors `src/**`. Pure, no WordPress.
- `tests/Golden/**` holds the parity tests of the engine against the golden fixtures.
- `tests/Integration/**` needs a WordPress or a fake server on `127.0.0.1`.
- `tests/Fakes/**` holds fake servers, in-memory implementations of `Contracts` and the doubles every module shares (clock, sleeper, HTTP client, logger, quota ledger, connector source). There is one double per need: do not add a second clock or HTTP double next to a module's tests.
- `tests/e2e/**` holds the end-to-end scripts (install, seed, Playwright).
- `tests/Support/**` holds helpers: `Fixtures` (paths, the parity preset), `Golden` (golden cases and vocabulary), `SourceScan`, `SignaturePrinter`, `ContractsDoc`.

During a phase, run only your own folder; the integration phase runs the whole suite.

## 6. Read-only guarantees (M1 and M2)

No code path may send POST, PUT, PATCH or DELETE to a PMS or pricing system. Four independent guards:

1. **Contracts:** every write method of `PmsConnector` and `PricingConnector` throws `NotAvailableInThisMilestone`. Implementations get this from the traits `Contracts\Support\PmsWritesUnavailable` and `PricingWritesUnavailable`; they do not write their own.
2. **Write token:** every write method requires a `Contracts\WriteToken`, whose constructor is private and which has no factory until the milestone that adds the write gate. Nobody can call a write method without forging one by reflection: a token cannot be rebuilt by `unserialize()` either (`__unserialize` throws). Sealing the tokens the gate issues (as `Validator::sealed()` does for plans) comes with the gate in M3.
3. **HTTP:** the `HttpClient` implementation refuses mutating methods with `WriteBlocked` before anything leaves the site.
4. **No write call:** the shadow stage holds no connector, and a source test fails when anything outside `src/Connectors` calls a write method of a connector.

In M2 the plan assembly builds the payload and the slices of what it would write and the shadow run stores them (`run_payloads`); no code path sends them: the write methods still throw `NotAvailableInThisMilestone` and `WriteToken` still has no factory (the write gate comes in M3).

The end-to-end check (`bin/e2e.sh`) asserts, from the simulator's own log, that a shadow run sent no PUT, PATCH or DELETE and no POST other than the calendar read below.

**The pricing system's calendar read (`POST /listing_prices`).** The verified API notes list it as a read that uses POST, which guard 3 forbids. Decision of the project manager (milestone 2): that request only reads (it changes nothing) and the engine needs it (price, floor and ceiling in force), so it is **on by default**:

- `WordPress\ConnectorFactory` puts exactly that method, host and path on `ReadRouteAllowList` (nothing else is allowed: any other POST, and that POST to another host or path, is still refused by guard 3); the run reads the calendar and the measure stage also compares the units sold of both systems.
- A site switches it off with `define('RITMO_PRICING_CALENDAR_READ', false);` in wp-config.php (any other value, or no definition, leaves it on). Then the run takes the current price of each night from the PMS (`readInventory(..., withPrices: true)`), never calls `readPrices()`, and nothing but GET leaves the site.

The end-to-end test proves both ways (only GET plus that POST by default; only GET with the constant set to false).

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

**Milestone 2.** Each area has its generator and its goldens: `golden_relations.py`, `golden_ladder.py`, `golden_stay.py` and `golden_plan.py` write `tests/fixtures/golden/m2/<area>/` with their own MANIFEST and frozen inputs, and the parity values of each area (`tests/fixtures/presets/parity-<area>.json`), which `make_parity_preset.py` joins into `parity.json`. **Level 2**: `golden_level2.py` runs whole plans of the reference engine (its plan, the delete-first plan, the no-effect filter, the validator, the payload and the slices) over ten frozen scenarios and writes `tests/fixtures/golden/level2/pace.plano.json`; `tests/Golden/Level2Test.php` compares `PlanAssembler` with it, per property (ENGINE.md, section 1.10). The deliberate differences are written in the area documents and in the tests, never silently: in `php_expected` of the goldens, in `tests/fixtures/golden/level2/plugin-divergences.json`, or as a named adjustment in the test.

## 8. What is not here yet

M2 brought into the rule set schema the sections `relations`, `price_bands`, `ladder`, `soldout`, `cadence`, `no_effect`, `orders`, `stay` and `plan` (each with neutral defaults, ranges and help; `RuleSetValidator` checks them and the room and property references they hold), and the plan assembly of a whole run (ENGINE.md, section 1.8). Still to come: writing (M3: write gate, writer, confirmation, step ledger recorded from what was written, scheduler, alerts), the source of the market ruler of a ladder strategy and of the nights closed in the PMS, editors and the nights grid (M4), the assistant, licence and packaging (M5). Dated owner exceptions to the safety limit are not modelled. The parity preset tool lists what it leaves out each time it runs.
