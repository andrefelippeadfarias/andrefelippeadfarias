# Ritmo Revenue: the optional assistant (milestone M5)

The assistant uses the customer's own Claude API key to put the plugin's numbers into words and to
turn a request in plain words into a **draft** that a person reviews. It is **off by default** and
the plugin works the same without it. It never writes a price, never creates an order in force or a
rule version, never approves, and never touches a mode, the emergency switch or a safety limit: a
draft only reaches the editors of M4, where a person decides and saves (or not).

Three uses, each with a fixed prompt (`Domain\Ai\PromptLibrary`, pinned by a test, in English); the
answer comes in the site's language (`get_locale()`, named in the user message):

1. **Report text**: the weekly panel and a run report in words, from facts the plugin computed and
   formatted. The text may only cite numbers of its input (`NumberCheck`); any other number throws
   the text away.
2. **Alert explanation**: what happened, why it matters and the next step inside the plugin.
3. **Request to draft**: "raise the Saturdays of December by 10% in the suite", "do not sell below
   900 on the holiday" become an owner order for the order form, or rule changes for the person's
   rules draft, through a structured answer checked again in PHP.

## 1. Where each piece is

| Folder | Classes | What they do |
|---|---|---|
| `src/Domain/Ai` | `AiSettings`, `AiModel`, `AiEffort`, `PriceTable`, `ModelPrices`, `TokenUsage`, `MonthlyUsage`, `AiPurpose`, `AiRequest`, `AiReply`, `AiModelInfo`, `AiGateway`, `AiStopped`, `PromptLibrary`, `PromptBuilder`, `DataBlock`, `PrivacyGuard`, `NumberCheck`, `OutputSchemas`, `JsonShape`, `AnswerReader`, `RuleCatalogue`, `DraftReader`, `DraftContext`, `AiDraft`, `OrderDraft`, `RuleChange`, `WeeklyFacts` | Pure PHP: settings, prices and cost, the prompts and their data, guest data out, the schemas, the reading of every answer, the checks of a draft. Depends on `Domain` only (`AiLayerTest`). |
| `src/Connectors/Ai/Claude` | `ClaudeConfig`, `ClaudeClient`, `ClaudeProvider`, `MessageBody`, `ReplyParser`, `AiHttpGuard` | The Claude API by plain HTTP through `Contracts\HttpClient` (no SDK). `ClaudeProvider` implements `Contracts\AiProvider` and `Domain\Ai\AiGateway`. |
| `src/Application/Ai` | `AiAssistant`, `AiStatus`, `AiText`, `AiSettingsStore`, `UsageLedger`, `AiDraftQueue`, `DraftHandOff`, `RequestData` | The use cases: when a call may leave, the usage of each call, the drafts waiting for a person, how a draft reaches the editors. |
| `src/Admin/Ai` | `AiScreens` (registry), `AiScreen`, `AiActions`, `AiServices`, `AiData`, `AiWords`, `AiResult`, `AiCapability` | The Assistant screen (Ask, Settings, Usage and cost), its forms, the wiring of one request, every text in words. |
| `src/Rest/Ai` | `AiRoutes`, `AiEndpoints` | The routes of `ritmo/v1` (section 6). |
| `templates/admin/ai` | `ask`, `settings`, `usage`, `explain` | Markup only; every value escaped. |
| `assets/ai.css`, `assets/ai.js` | | Styles (cards below 600 px, focus, reduced motion) and progressive enhancement (the request form waits for the answer with a status; the "explain" buttons add the answer as text). Every action works without the script, except the "explain" buttons, which are hidden without it. |
| `tests/fake-apis/AnthropicApi.php` | | The fake Claude API (section 8). |

## 2. Settings (Assistant › Settings, `ritmo_manage_connections`)

| Setting | Default | Notes |
|---|---|---|
| On | off | Nothing is sent while off, whatever else is set. |
| Key | none | Saved in the sodium vault (`anthropic_api_key`), write-only: the screen says whether one is saved and since when; "Remove the key" forgets it. |
| Model | `claude-opus-5-5` | Choices `claude-opus-5-5`, `claude-sonnet-5-5`, `claude-haiku-5-5` (exact ids, no date suffix). Kept as text; once the connection was tested, a model the key cannot use (GET `/v1/models`) is refused on save and stops every call. The cost is the customer's decision. |
| Effort | `medium` | `low`, `medium` or `high`, sent explicitly as `output_config.effort` (Opus 5.5 defaults to medium; other models differ). |
| Fallback | on | The server-side fallback of a refused request: header `anthropic-beta: server-side-fallback-2026-07-01` and `"fallbacks": "default"`. An answer of the fallback model is recorded as such (a `fallback` content block or a `fallback_message` iteration). |
| Monthly cap | 20 US dollars (1 to 10 000) | When the estimated spend of the calendar month (site time) reaches it, the assistant stays off until the next month or until the cap is raised. |
| Wait for an answer | 120 s (15 to 300) | The timeout of one call; retries also stay inside the time the PHP request has left. |
| Prices | per million tokens: Opus 5.5 4 / 20 (cache read 0.20, cache write 5), Sonnet 5.5 2 / 10 (0.10, 2.50), Haiku 5.5 0.10 / 0.50 (0.01, 0.125), any other model 5 / 25 (0.50, 6.25) | For the estimate only; the Anthropic invoice is the reference. "Any other model" prices a fallback model high on purpose. |

"Test the connection" lists the models of the key (GET `/v1/models`, no cost) and keeps the list.
Like every call it needs the assistant on and a key.

## 3. The request to Claude

`POST {base}/v1/messages`, headers `content-type: application/json`, `x-api-key`, `anthropic-version:
2026-10-21` (and the fallback beta header when the fallback is on); `GET {base}/v1/models?limit=100`
to test. The body is built from a whitelist (`MessageBody::KEYS`): `model`, `max_tokens` (16000,
no streaming), `system` (text blocks; the last stable block carries `cache_control: {type:
ephemeral}`; nothing variable in it), `messages` (one user message), `output_config` (`effort`, and
`format: {type: json_schema, schema}` for structured answers) and `fallbacks`. Never `temperature`,
`top_p`, `top_k`, `thinking`, `budget_tokens`, `tool_choice` or an assistant prefill (this API
version and these models refuse them; the simulator refuses them too).

**Answers.** The stop reason is read first: `refusal` (with `stop_details.category`) and `max_tokens`
become a clear message, never a text or a draft. Empty `thinking` blocks are skipped; the text is
the first `text` block; a structured answer is decoded and checked again against its schema in PHP
(`JsonShape`), then for meaning (`DraftReader`).

**Errors** (`ClaudeClient`). 400, 401, 402, 403, 404 and 413 are never repeated and say what to fix
(401/403 are `AuthFailed`). 429 waits what `retry-after` asks when it is at most 30 s and time
allows, else stops (`RateLimited`). 500, 503, 504 and 529 are repeated at most twice, after 2 and
then 4 s (or the `retry-after` given), only when the wait and another answer fit in the time left
(a margin of 5 s is kept; an attempt is never shorter than 10 s). A request that got no answer
(timeout, refused connection) is not repeated: the model may have answered and billed. Logs carry
the status, the error type and the attempt, never the key, a prompt or an answer.

**HTTP layer.** The assistant has its own client (`Admin\Ai\AiServices`): the plugin's read-only
guard (`GuardedHttpClient`) with its own `ReadRouteAllowList` holding exactly `POST
api.anthropic.com/v1/messages`, then `AiHttpGuard`, which lets only that route and `GET /v1/models`
leave, to the one host of the base URL. The pricing and PMS connectors never get this client, the
Claude host is on no list of theirs, and their hosts are on none of the assistant's
(`ClaudeOverHttpTest`). A base URL other than production comes from `RITMO_ANTHROPIC_BASE_URL`,
honoured only while `RITMO_DEV` is true.

## 4. Safety of the drafts and prompt injection

- Data goes between delimiters (`<data>`, `<request>`) as JSON in which every `<`, `>`, `&` and
  quote is a `\u` escape (`DataBlock`): a room name, an alert text or the person's words cannot close
  a delimiter or open a tag. The system prompt says that everything inside is data, never
  instructions.
- The draft schema allows only the fields of a draft (`OutputSchemas::draft`): kind (`order`,
  `rule_change`, `not_supported`), summary, the order fields, rule changes as key and value, and
  questions. Nothing in it can name a mode, a key, an approval or a write; releases of an order are
  never drafted (a person ticks them in the form).
- `DraftReader` checks again: rooms of the property; nights from today to a year ahead (weekdays
  expanded by the plugin); **amounts, percentages and percentiles must be numbers the person
  wrote**; the floors of a relative change are computed by the plugin from each room's base price
  (half to even, two decimals), never by the model; rule changes only for settings of the catalogue
  (`RuleCatalogue`: numbers, switches and choices; never a locked field, never `safety.*`,
  `write.*`, `modes.*`, `quota.*`, `approval.*`, `third_party.*`, `verify.*`, `waves.*`,
  `schedule.*`, `light_run.*`, `alerts.*`, `reports.*` or `floors.fraction_of_reference_min`), in
  their range, applied to a copy of the rules in force and validated as a whole rule set.
- `DraftHandOff` checks once more when a person opens a draft (the catalogue and
  `Rules\SafetyFields`): an order fills the order form (`OrderFormDraft`, the form's own draft; the
  order is only given when the person saves it, with `OrderDesk`'s checks, safety releases included);
  rule changes go into the person's rules draft (`DraftDesk::stage`), shown in "Review and save";
  the rules change only when the person saves a version (`RuleSetEditor`).
- A model that follows an injected instruction still gets nothing through (`AiAssistantTest`,
  `m5-ai.js`).

## 5. Guest data and what is kept

- `PrivacyGuard::scrub` drops every key that names guest data (name, guest, mail, phone, contact,
  document, CPF/CNPJ, address, note, comment, message, customer...) from the data of a prompt;
  `PrivacyGuard::problem` refuses a text with an e-mail, a phone number or a CPF/CNPJ (dates,
  amounts, percentages and booking ids up to nine digits are not phone numbers). A request or data
  with such a text is **never sent**; the person is told why. Answers are checked the same way.
- Kept: per calendar month (`ai_usage` option) the totals and the last 200 calls with when, what
  for, the model that answered, the tokens (input, output, cache write, cache read), the estimated
  cost, whether the fallback answered and how it stopped. **Never** the prompt or the answer. The
  month before is kept as totals (`ai_usage_previous`).
- Drafts waiting for a person (`ai_drafts`, at most 30, 14 days): the request as typed (already
  checked for guest data) and the checked draft.
- The weekly text is shown once (a transient of 10 minutes for the person), never stored.

## 6. REST (`ritmo/v1`)

| Route | Capability | Answer |
|---|---|---|
| `GET /ai/status` | `ritmo_use_ai` | ready or the reason, model, spend and cap |
| `POST /ai/drafts` `{property, request}` | `ritmo_use_ai` | 201 with the draft in words (queued), or why not |
| `POST /ai/reports/weekly` `{property}` | `ritmo_use_ai` | the weekly panel in words |
| `POST /ai/runs/{id}/report` | `ritmo_use_ai` | a run's report in words |
| `POST /ai/alerts/{id}/explain` | `ritmo_use_ai` | explanation and next step |
| `POST /ai/test` | `ritmo_manage_connections` | the models of the key |

Each checks the REST nonce and its capability first (`RestRoutes::allowed`). A stop answers 409
(off, no key, cap, model), 422 (the request or the answer was not accepted: guest data, refusal,
cut answer, unknown number, refused draft, not supported), 502 (Claude failed) or 404 (unknown
property, run or alert), always with a sentence in the reader's language.

## 7. Form actions (Assistant screen)

| Action | Capability (dispatcher) | Also checked | Effect |
|---|---|---|---|
| `ritmo_ai_settings_save` | manage connections | | settings and, when typed, the key |
| `ritmo_ai_key_forget` | manage connections | | forgets the key |
| `ritmo_ai_test` | manage connections | | lists the models of the key |
| `ritmo_ai_draft` | view | `ritmo_use_ai` | a request becomes a queued draft |
| `ritmo_ai_weekly` | view | `ritmo_use_ai` | the weekly text, shown once |
| `ritmo_ai_draft_open` (nonce per draft) | manage rules | `ritmo_use_ai` | opens the draft in its editor |
| `ritmo_ai_draft_discard` (nonce per draft) | view | `ritmo_use_ai` | discards a draft |

The dispatcher's capability must be a constant of `Capability` or `Roles` (lint); until the
integration adds `Capability::USE_AI`, the actions that ask the assistant check `ritmo_use_ai` in the
handler, before anything.

## 8. Simulator and tests

`tests/fake-apis/AnthropicApi.php` answers in the format of the real API: text, structured JSON,
refusal with `stop_details`, `max_tokens`, fallback (block and usage iteration), 400/401/402/403/
404/413, 429 with `retry-after`, 500/529, usage with cache writes the first time a system prompt is
seen and cache reads after; GET `/v1/models` (a key containing `haiku-only` gets one model). It
refuses what the real API refuses (sampling parameters, disabled thinking or a thinking budget,
forced tool choice, a prefill, `fallbacks` without the beta header, a schema with ranges or an open
object, another version header). Its control plane: `POST /__anthropic/script`, `GET
/__anthropic/log` (never the key), `POST /__anthropic/reset`. Served alone (`php -S ...
AnthropicApi.php`) or in process; also under an `/anthropic` prefix.

- `tests/Unit/Domain/Ai`: drafts (every refusal), privacy, numbers, delimiters, prompts (pinned),
  catalogue, schemas, settings, prices, usage, answers, weekly facts, layers.
- `tests/Unit/Connectors/Ai`: the request body and headers, the answers, errors and retries, the
  time budget, the guard, the key never dumped.
- `tests/Unit/Application/Ai`: nothing leaves while off or without a key (the connector is not even
  built), the cap, models of the key, refusal, fallback, drafts, injection, hand-off, queue.
- `tests/Unit/Admin/Ai`: texts of every stop and refusal, capability checks, write-only key.
- `tests/Integration/Ai`: over real HTTP (cURL) to the simulator on 127.0.0.1, the REST answers,
  and the Portuguese of every text (`ai-pt_BR.po`, merged by the integration).
- `tests/e2e/m5-ai.js` (Playwright): off by default, write-only key, connection test, an order
  draft opened in the form, a rule draft in the review, injection and guest data refused, weekly
  text, usage without content, off again, no write to the PMS or the pricing system, every screen at
  1280 and 390 px.

## 9. Registration (for the integration)

- `Roles`: `USE_AI = 'ritmo_use_ai'` in `CAPABILITIES`, grants version 3: manager and
  administrator get `ritmo_use_ai` (update `RolesTest`). `Capability::USE_AI`; then
  `AiActions` may name it in its `FormAction`s.
- Menu: entry `AiScreen::SLUG` ("Assistant", `ritmo_view`) after Operations; renderer and actions
  from `AiScreens::screens()` / `AiScreens::actions()`; assets on that screen: `editors.css`,
  `ai.css`, `ai.js`.
- REST: `Rest\Ai\AiRoutes::register()` on `rest_api_init`.
- Alerts screen (detail of an alert) and run reports: `View::render('admin/ai/explain', $view)` with
  `AiScreens::explainView('alert'|'run', $id)` when it is not null, and `ai.css`/`ai.js` enqueued
  there.
- `bin/wp-test-site.sh`: `define('RITMO_ANTHROPIC_BASE_URL', 'http://127.0.0.1:$API_PORT/anthropic')`
  and the simulator mounted under `/anthropic` (router or a second `php -S`); `bin/e2e.sh`: run
  `tests/e2e/m5-ai.js` on a seeded site.
- Translations: merge `tests/Integration/Ai/ai-pt_BR.po` into `languages/ritmo-revenue-pt_BR.po`,
  then `php bin/make-pot.php` and `php bin/compile-l10n.php`.
- Uninstall: nothing new to add: the options `ritmo_ai_settings`, `ritmo_ai_usage`,
  `ritmo_ai_usage_previous`, `ritmo_ai_drafts`, the vault entry `anthropic_api_key` (inside
  `ritmo_vault`) and the transients `ritmo_ai_result_*` go with the `ritmo_` prefixes the uninstaller
  already removes.
- Diagnostics/support bundle: may show whether the assistant is on, the model and the month's
  spend; never the key.
