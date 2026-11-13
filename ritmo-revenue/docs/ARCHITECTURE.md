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
- `Domain` texts (schema labels and help) are plain English strings because `Domain` cannot call WordPress. The admin layer translates them when rendering through `Admin\Components\DomainText::t()`, the only `__()` with a text that is not literal; `bin/make-pot.php` lists them from the schema. The run report is stored as codes with counts (`Domain\Report\RunLines`) and translated for each reader (`Admin\Reports\RunLineText`).
- Translation files (`languages/`, spec 05 section 7): `bin/make-pot.php` extracts every literal call of the WordPress translation functions with the plugin's domain (`token_get_all`, no network) and the schema texts into `ritmo-revenue.pot`; `ritmo-revenue-pt_BR.po` is the source of the Portuguese translation (100%: a test fails on any text without a translation, with other placeholders or against the glossary `glossary-pt_BR.json`); `bin/compile-l10n.php` writes `ritmo-revenue-pt_BR.mo` and `ritmo-revenue-pt_BR.l10n.php` (the format WordPress 6.5+ reads first). Tests fail when the `.pot` or the compiled files are stale. The `Application` layer stays free of WordPress, so the alert e-mails (`Application\Alerts\MailText`) are still English.

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
bin/test.sh                          lint (bin/lint.php), then the whole suite
bin/test.sh tests/Unit/Domain        one folder or file
bin/test.sh NumTest                  a filter
php bin/lint.php                     the plugin's own lint
php bin/make-pot.php [--check]       the .pot from the code
php bin/compile-l10n.php [--check]   the .mo and .l10n.php from the .po
```

`bin/lint.php` (the plugin's own lint: WPCS cannot be downloaded here; rules in `bin/lint/Lint.php`, tested by `tests/Unit/Tools/LintTest.php`): `php -l` on every PHP file; every value a template prints is exactly one call of `esc_html`, `esc_attr`, `esc_url`, `esc_textarea`, `wp_kses` or `wp_kses_post` (and `_e()` is refused); no template reads `$_GET`, `$_POST`, `$_REQUEST`, `$_COOKIE`, `$_FILES` or `$_SERVER`; every `register_rest_route()` has a `permission_callback` that is not `__return_true`; only the dispatcher (`Admin\Screens\ScreenActions`) hooks admin-post.php and it checks POST, then the capability, then the nonce, before the handler, and every form action names a capability of the plugin; nothing is loaded from another site (enqueued files, `<script>` and `<link>` tags, the stylesheets and scripts of `assets/`).

`bin/test.sh` downloads PHPUnit (a `.phar` in `tools/`, outside git) from phar.phpunit.de on first use, keeps it only when its sha256 matches one of the pinned releases listed in the script (there is no unverified fallback: when every pinned download fails, the script stops and asks for a new pinned entry) and exits with PHPUnit's code. A `.phar` already in `tools/` that matches no pinned checksum is downloaded again. The suite treats warnings, notices, deprecations and risky tests as failures.

**Owner vocabulary.** `ArchitectureTest` fails when the names, room codes or ids of the pilot properties (`tests/fixtures/owner-terms.json`) appear in the plugin's code, presets, templates, assets, README or `docs/`. The only exception is `docs/PLANO.md`, marked internal: it stays in the repository and is excluded from the distributed zip by the packaging of M5. Those names may appear only under `tests/` (the parity preset, the frozen inputs and the golden tests built on them).

```
bin/e2e.sh [screenshot folder]       end-to-end test on a throwaway WordPress (needs Node with Playwright and Chromium)
bin/wp-test-site.sh start|restart|stop   the throwaway WordPress alone (.wp-test/, outside git)
```

`bin/e2e.sh` builds WordPress with SQLite from wordpress.org (cached in `.wp-test/downloads`) and runs, against the simulator: the setup wizard on a fresh site; on a seeded site the passes of M1-M3 on the M4 screens (`shadow-run.js` with and without the pricing calendar read: only reads; `operations.js`: nothing leaves before the approval, never a price or a booking to the PMS, the emergency switch stops every write); on a new seeded site the M4 screens, editors, market and reports (`m4-screens.js`, `m4-editors.js`, `m4-reports.js`), the screens in Portuguese (`m4-i18n.js`: no English sentence of the plugin) and the WordPress checks; then the update from the M3 plugin (`upgrade-checks.php`). Screenshots of every screen in English and Portuguese at 1280 and 390 px go to `tests/e2e/screenshots/`. See APPLICATION.md, section 1.7.

- `tests/Unit/**` mirrors `src/**`. Pure, no WordPress.
- `tests/Golden/**` holds the parity tests of the engine against the golden fixtures.
- `tests/Integration/**` needs a WordPress or a fake server on `127.0.0.1`.
- `tests/Fakes/**` holds fake servers, in-memory implementations of `Contracts` and the doubles every module shares (clock, sleeper, HTTP client, logger, quota ledger, connector source). There is one double per need: do not add a second clock or HTTP double next to a module's tests.
- `tests/e2e/**` holds the end-to-end scripts (install, seed, Playwright).
- `tests/Support/**` holds helpers: `Fixtures` (paths, the parity preset), `Golden` (golden cases and vocabulary), `SourceScan`, `SignaturePrinter`, `ContractsDoc`.

During a phase, run only your own folder; the integration phase runs the whole suite.

## 6. Write guarantees

From milestone 3 the plugin writes, and only through one path. Every new property starts in shadow mode (the migration default and `PropertyMode`, forced again by a preset import), where nothing leaves the site but reads. The guards, each one enough to stop a write on its own:

1. **One issuer of tokens.** Only `Application\Write\Gate\WriteGate::issue()` makes a `Contracts\WriteToken` (private constructor, bound by `Closure::bind` in the gate only; a source test allows `bind` only there and in the `Validator`). A token comes only for a `ValidatedPlan` sealed by the `Validator` of this process, made for the property's today (read from the clock in the property's timezone at the moment of issue), validated at most `write.max_validation_age_minutes` ago over reads at most `write.max_read_age_minutes` old, with the emergency switch released, in a mode that allows it (an approval by a person who holds `ritmo_approve` now, over exactly the approved subset; or the autonomous mode switched on by a person who manages the connections, typing the property id), with the run holding the property's run lock, and over the listing bases the run read (every percentage of a run is over the base the pricing system applies now: the run's rooms carry the listing values read, a change is stored and alerted, and a plan over another base is refused). The gate checks tonight (no floor or ceiling raised or released over the current price, no deletion that frees tonight's price) and the fields each system may get. Listing values (minimum, base, maximum) need an approval that names them and `write.listing_changes_enabled`; never in an autonomous run.
2. **A token cannot be forged.** Its power is in the gate's live registry (a `WeakMap`), not in the object: a token made by reflection (`newInstanceWithoutConstructor`), `unserialize()` (`__unserialize` throws) or `clone` (private) is not in the registry and is refused. A token is for exact steps (operation, targets, nights, fields and the values of each entry), each usable once; it ends after `write.token_ttl_minutes`, never later than the property's midnight, at the end of the run, and at once when the emergency switch is engaged.
3. **Connectors check the token.** The PMS steps of a token come only from the PMS changes sealed with the plan (`Plan::$pmsChanges`, checked by `Stay\Write\PmsChangeCheck` before the validator seals them): the gate takes no list of changes from its caller.
   Every write method of the connectors asks the write authority (the gate) before building the request; without an authority they throw `WriteBlocked`. Bodies are built by whitelists (`OverrideWriteBody`, `ListingUpdateBody`, `CalendarWriteBody`): never a fixed price or a lead-time expiry to the pricing system, never a price or a booking (create, change, cancel) to the PMS; the PMS gets only availability, minimum and maximum stay, arrival and departure restrictions and channel limits.
4. **HTTP.** `GuardedHttpClient::request()` sends only GET (plus the calendar read below); `write()` sends only routes of the `WriteRouteAllowList` (exact paths; a booking route cannot be declared) and only the request a connector has just used a step of a live token for (`WriteAuthority::claim()`; the same request may go once more after a PMS token renewal): a live token alone lets nothing through.
5. **One sender.** A source test fails when anything but `Application\Write\Execute\StepSender` calls a write method of a connector, and when anything outside the write path names the gate (except the composition and the two writers of a run: `WriteRunner`, `UndoWriter`).

How a write goes (WRITE.md): in series, slice after slice (the Slicer's slices, a deletion followed at once by the rewrite of its nights); before every slice the executor checks the lock, the emergency switch, the property's day, the token and the time left; it stops at the first 400, 429, 5xx or timeout. Each step is journaled as `sending` before its call (`write_ops`); after a stop or a crash nothing is sent again from the journal, and a run whose process died while writing is never continued (it ends as failed): the next run's watch reads the systems again before anything is decided, classifies every night (applied, not applied, divergent), records what the dead request left applied (change log, step ledger: no second pace step), freezes the nights someone else changed meanwhile, keeps a group left between its deletion and its rewrite open (and alerted) until its repair is in force; the run repairs it first (autonomous, or once a person approves the repair), builds and validates a new plan and sends only what is missing. A step counts as written only when the read-back finds it stored; only then does it enter the change log (before, after and the inverse operation) and the step ledger, and a pace step counts as delivered only when the room's push came after it and the PMS price is inside the step. The recalculation quota counts every attempt (failures and 429 included) by listing over a rolling 24 hours, recorded as pending before the call; its settings can only make the provider's limits stricter, and a failed recalculation is not tried again before the next scheduled routine. A cancellation wave holds price cuts until a person releases it; a night someone else changed is frozen and never overwritten while frozen; an item without effect, or with only a rounding effect, is dropped before the token.

The emergency switch (option or constant) stops every run that may write before its next stage and the executor before its next slice; scheduled runs go on as shadow runs (they read and watch). Engaging it, lowering a mode or approving a newer plan ends every approval not written yet: an approval never writes after the change. A request that does not say clearly how to set the switch never releases it, and a decision on a plan that is not exactly "approve" or "reject" never approves. Undo is a plan like any other: validated, approved by the person who asked, issued a token and sent by the executor.

**The pricing system's calendar read (`POST /listing_prices`).** Decision of the project manager (milestone 2): that request only reads and the engine needs it, so it is **on by default**: `WordPress\ConnectorFactory` puts exactly that method, host and path on `ReadRouteAllowList`. A site switches it off with `define('RITMO_PRICING_CALENDAR_READ', false);` in wp-config.php; then the run takes the current price of each night from the PMS.

The end-to-end checks prove it from the simulator's own log: a shadow run sends no PUT, PATCH or DELETE and no POST but the calendar read; an approval mode run writes nothing until a person approves on the screen, and then only the approved entries; with the emergency switch engaged on the screen nothing is written (`tests/Integration/Run`, `bin/e2e.sh`).

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

M2 brought into the rule set schema the sections `relations`, `price_bands`, `ladder`, `soldout`, `cadence`, `no_effect`, `orders`, `stay` and `plan`; M3 added `write`, `quota`, `verify`, `approval`, `third_party`, `waves`, `modes`, `schedule`, `light_run` and `alerts`; M4 added `market`, `reports` and `stay.write` (neutral: off). M4 brought the twelve screens, the editors, the market price files, the reports, the stay and arrival/departure writes of full runs, the translation to Portuguese and the lint. Open points of M4 are listed in OPERATIONS.md, section "Open points"; the assistant, licence and packaging come in M5. Dated owner exceptions to the safety limit are not modelled. The parity preset tool lists what it leaves out each time it runs.
