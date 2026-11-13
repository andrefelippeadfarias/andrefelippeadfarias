# Ritmo Revenue: the write path (M3)

How a validated plan reaches the pricing system and the PMS, and why nothing else can: the write
gate, the token and its live registry, the schedule of steps, the serial executor with its
write-ahead journal, the resume after a stop or a crash, the recalculation quota, the field locks
and the write door of the HTTP layer. Specification: `03-write-path.md` of the M3 specs (invariants
W1–W66, decisions D1–D17); this page says what is built and where the plugin differs on purpose.

## 1. Where it lives

```
src/Application/Write/
  WriteConfig                      section "write" (neutral defaults; parity in tests/fixtures/presets/parity-write.json)
  Gate/      WriteGate (issue, verify, isLive, revoke; implements Contracts\WriteAuthority), TokenGrant,
             WriteAuthorization, AutonomousAuthorization, HumanApproval, WriteContext, TonightState,
             TonightGuard, FieldLocks, PlanHash, IssuedToken, WriteRefused
  Schedule/  WriteSchedule, WriteStep, CalendarChange
  Execute/   WriteExecutor, StepSender, PmsGuard, StoredCheck, RunProgress, WriteRun, WriteOutcome, StepResult, StopReason
  Journal/   WriteJournal, StateReader, StoredState, NightRule, OpStatus
  Resume/    ResumeService, ResumePlanner, BrokenGroup, ResumeReport, NightClass
  Quota/     RecalcQuota, RecalcGuard, AttemptLog, RollingWindow, RecalcAttempt, AttemptOutcome, QuotaVerdict,
             QuotaConfig (section "quota"), AttemptRefused
src/Contracts/        WriteToken (sealed), WriteAuthority, WriteHttpClient, WriteRequest, WriteOperation, Exception\NotReady
src/Infrastructure/Http/   WriteRouteAllowList; GuardedHttpClient, ThrottledHttpClient, CreditAwareHttpClient get write()
src/Infrastructure/Db/     Migration/Migration0007WritePath, Repository/WriteOpRepository,
                           Repository/RecalcAttemptRepository
src/Connectors/PriceLabs/  OverrideWriteBody, ListingUpdateBody, the four write methods
src/Connectors/Beds24/     CalendarWriteBody, writeCalendar(), Beds24Config::$roomUnits
```

Only `Execute/StepSender` calls a write method of a connector, and nothing outside
`Application/Write` names the gate (source tests `WriteSourceRulesTest`, `WordPressLayerRulesTest`).

## 2. The gate and the token

`WriteGate::issue(ValidatedPlan, WriteAuthorization, WriteContext, pms changes, listings to
recalculate)` builds the steps of the plan itself (PayloadBuilder, Slicer, WriteSchedule), so a
token covers exactly what the Validator sealed. With the clock read once, it refuses (`WriteRefused`
with a reason code) unless every check holds:

| # | Check | Code |
|---|---|---|
| G1 | `Validator::sealed()`: the plan was validated in this process | `unsealed_plan` |
| G2 | the plan is of the property's today (clock, property timezone) and of this property | `stale_plan`, `wrong_property` |
| G3 | validated at most `write.max_validation_age_minutes` ago | `stale_validation` |
| G4 | the calendars were read at most `write.max_read_age_minutes` ago (never in the future) | `stale_reads` |
| G4b | the plan's percentages are over the listing bases the run read (`WriteContext` rooms carry the listing values read: `RunContext` and `UndoWriter` take them from the pricing system; within a cent) | `base_changed` |
| G5 | the emergency switch is released (read fresh) | `kill_switch` |
| G6 | the authorization allows it now: autonomous mode, or a person's approval of this plan hash, of today, by a user who holds the approval capability now; never shadow; the licence allows writing | `mode_shadow`, `mode_not_autonomous`, `licence`, `approval_mismatch`, `approval_stale`, `approver_lacks_capability` |
| G7 | a listing change only with listing changes enabled AND a person's approval naming it; otherwise it is left out (`dropped`, `listing_needs_approval`) and the rest goes on | — |
| G8 | tonight never rises (`TonightGuard`, with tonight's price and rule read in this run): a floor above the current price, a ceiling raised or released, the deletion of a rule that holds tonight down without a rewrite capping it at or below the current price; tonight unknown | `raise_today_floor`, `raise_today_ceiling`, `raise_today_deletion`, `tonight_unknown` |
| G9 | field locks (`FieldLocks`): no price, price type, fixed amount or expiry to the pricing system; PMS values inside the room (availability and channel limits 0..units, stays 1..30, min ≤ max), the room's own PMS id | `forbidden_field` |
| G12 | the run holds the site's write lock (heartbeat) | `lock_not_held` |
| — | something left to write | `nothing_to_write` |

The token (`Contracts\WriteToken`) is created only by `WriteGate::mint()` through its private
constructor (`Closure::bind`, the one exception besides the Validator's seal allowed by the
architecture test). It carries nothing but a random id: what it may do is kept by the gate's
static `WeakMap` registry (`TokenGrant`): the steps it covers (each usable once, with the values of
each entry: `WriteRequest::entriesOf()` of the body the connector got; a request with other values
is refused), its expiry
(`write.token_ttl_minutes`, never past the property's midnight), the property's day. `verify()`
(called by every connector write) and `claim()` (called by the HTTP guard for the exact method, URL
and body: only the write a step was just used for, once more for the same request after a PMS token
renewal) refuse a token that is not in the registry (made by `newInstanceWithoutConstructor`, `unserialize` throws, `clone` is
private), revoked (end of the run), expired, of another day, or alive while the emergency switch is
engaged (it is then revoked for good). A request outside the steps (another listing, night, field
or operation) or a step used twice is refused (closes the M2 pending item 7c).

## 3. The schedule

`WriteSchedule` turns the Slicer's slices (unchanged) and the PMS changes into the steps:
recalculations asked first; every deletion followed right after by the rewrite of its nights carved
out of the writes (one group key); the other writes in the Slicer's order (tonight, tomorrow, the
rest) with dates in the last `write.horizon_edge_days` of `write.horizon_days` in calls of their
own; the PMS changes in blocks of at most `write.pms_max_ranges_per_post` ranges (in the batch's
order: the M2 StayBatch puts the urgent nights first); the listing changes last.
`CalendarChange::fromStay()` turns the M2 PMS stay changes into calendar changes.

## 4. Execution

`WriteExecutor::execute(WriteRun)` reads the stored state of every target (StateReader, reads
only), inserts every step in the journal as `planned`, then, one step at a time:

- before the step: lock heartbeat (`lock_lost`), emergency switch (`kill_switch`), the property's
  day (`day_turned`), the token (`token_expired`), the time left (`time_budget`: a deletion of a
  group starts only with time for both halves, 2 × `write.call_timeout_seconds`);
- a recalculation goes only when the quota allows it; a listing refused by the quota gets none of
  its writes (`skipped`, `not_recalculated`, alert `not_confirmed_quota`, no call);
- a body refused `write.max_identical_refusals` runs in a row is `quarantined` (no call);
- a PMS block is checked right before it with the PMS read again (`PmsGuard`): a value the pricing
  system also pushes goes only after the pricing step of the same room and nights was accepted in
  this run (`pricing_first`); a change whose planned minimum stay is no longer the stored one is
  skipped (`already_at_target`, `changed_since_read`);
- the row is marked `sending` (committed) before the call; then the answer:

| Answer | Journal | Run |
|---|---|---|
| 2xx | `accepted`, then `stored` or `mismatch` after the read-back | next step |
| 400 "pricing data … not available yet" (`NotReady`) | `not_ready` | stops |
| other 4xx, `WriteBlocked` | `rejected` | stops |
| 401/403 (`AuthFailed`; a PMS write token is renewed once before) | `rejected` | stops |
| 429 (`RateLimited`) | `rate_limited` | stops |
| 5xx, 408, no answer, a PMS answer with a refused room | `unknown` | stops |
| process dies | stays `sending` | the next run reads it back |

After the first failure nothing more is written in the run; the rest is `abandoned` with the
reason. The token is revoked however the run ends. Each PMS block is read back right after it; the
pricing writes are read back at the end, one GET per listing (skipped after a pricing 429). A read
back that differs is `mismatch`, never rewritten in the same run. The outcome lists the alert codes:
`write_stopped`, `night_without_rule` (critical: a group stopped between its halves),
`write_unknown_outcome`, `write_mismatch`, `write_quarantined`, `pricing_first_skipped`,
`not_confirmed_quota`, `sync_now_requested` (tonight or tomorrow changed). Storing and sending
alerts is the operations area's.

## 5. Journal and resume

`write_ops` (migration 7, `Migration0007WritePath`): one row per step with the nights, the body (never
headers), `before_state`, `intended_state` (merge semantics: a write keeps the fields it does not
send; a deletion leaves no rule; a group rewrite leaves only its fields; PMS availability is read
back net of bookings, compared with the expected net value when given), `observed_state`, hashes of
the token, the plan and the body, and `resolved_run_id`.

`ResumeService` runs in the watch stage of every run, whatever its mode, before anything is decided
and before the third-party comparison: it reads again every target of the rows earlier runs left
unsettled (`planned`, `sending`, `accepted`, `unknown`, and the rows of a group not stored whole; a
group stored whole is resolved without a read) and classifies each night: applied, not applied,
diverged (`ResumePlanner`; the rewrite of a group is read even when it was never sent). Rows become
`stored`, `abandoned` or `mismatch`, keep what was read and are marked resolved; the rows a dead
request left applied are recorded as its verify stage would have (change log, step ledger,
observations: the next decision never gives a second pace step), and their nights are the plugin's
own for the third-party comparison; a night that diverged is someone else's (frozen). Nothing of the
old payload is sent again: the run builds and validates a fresh plan; items whose state is already
in force drop out of the payload (no effect), so only what is missing goes. A group whose deletion
applied and whose rewrite did not is a `BrokenGroup`: its rows stay unresolved (every run sees it
and raises `write_failed` with the open groups again) until its rewrite is in force; its rewrite
becomes plan items (`repair_group`) for the nights from today on, which go through the Validator and
the gate (tonight never rises) and are written first and alone: at once when autonomous, as the
plan proposed for approval in approval mode.

The write of a run happens once (`WriteRunner::guardOnce`): a request that finds journal rows of its
own run (its process died while writing, and the scheduler continued the run) ends the run as
failed (`WriteInterrupted`) instead of sending from the old reads. The executor also gets a deadline
from the request's time limit (`WordPress\TimeLimit` asks PHP for 300 seconds first): no call starts
without time to finish it.

## 6. Recalculation quota

`RecalcQuota::verdict()` is the reference's rule (golden `cota_recalculo.situacao`, 24 cases): 3 per
listing in a rolling 24 h (strict), 10 per account per rolling minute, after any 429 the account
waits a minute (except the listing's own 429 inside the run), a listing whose attempt failed or got a
429 since the run start waits for the next run; a morning-slot warning never blocks. Every attempt
counts, failures and 429 included. `RecalcGuard` records the attempt as `pending` BEFORE the call and
settles it after (`ok`, `failure`, `rate_limited`); a pending attempt older than the call timeout
counts as a failure. `RecalcQuota::runStartFor()` gives the run start, through `Run\QuotaRunStart`:
the slot of a full or light run, and for any other run (by hand, an approved write, a retry) the
latest scheduled routine, at least `quota.failure_cooldown_minutes` before now (D6; the reference's
`inicio_rodada`). The settings can only make the provider's limits stricter: `quota.per_listing` at
most 3, `quota.window_hours` at least 24, `quota.per_account_per_minute` at most 10,
`quota.account_wait_after_429_seconds` at least 60 (`QuotaConfig` refuses anything else).
`AttemptLog` keeps the recording rules (golden `cota_recalculo.registrar`); instants need a timezone.

## 7. Field locks, four layers

1. Gate: `FieldLocks` and `TonightGuard` over every step.
2. Connectors: `OverrideWriteBody`, `ListingUpdateBody`, `CalendarWriteBody` build bodies from
   whitelists (golden `beds24.corpo_edicao`) and refuse anything else with `WriteBlocked` before
   any request; then the write authority is asked for exactly that request.
3. HTTP: `GuardedHttpClient::write()` lets a request through only on a `WriteRouteAllowList` route
   (exact method, host and path pattern; a booking route cannot be declared) with a live token;
   `request()` stays read-only. A client built without write routes and authority never writes.
4. Source tests: only `StepSender` calls a write method; only the write path names the gate.

Never written: a price to the PMS, any booking (create, change, cancel), a fixed amount, an expiry.

## 8. Simulator

`tests/fake-apis` has a writable world, off by default (the M1/M2 end-to-end checks are unchanged):
`POST /__writes` turns it on (date rules merged per date, deleted whole, all-or-nothing 400, the
"not available yet" 400 until a listing is recalculated, recalculation limits with failures
counting, PMS calendar with availability net of bookings and channel limits as text, 405 for any
other write and every write body kept); faults take a mode (`apply_then_fail`, `drop_silently`,
`delay`). Tests run it in process (`tests/Integration/Write/Support/InProcessApis`, which also
simulates a crash after N writes and lost answers) and over HTTP (`WriteOverHttpTest`).

## 9. Settings

| Key | Range | Default | Parity |
|---|---|---|---|
| `write.max_read_age_minutes` | 5–180 | 30 | 30 |
| `write.max_validation_age_minutes` | 1–60 | 10 | 10 |
| `write.token_ttl_minutes` | 1–60 | 15 | 15 |
| `write.horizon_days` / `write.horizon_edge_days` | 30–730 / 0–30 | 365 / 2 | 365 / 2 |
| `write.max_identical_refusals` | 1–5 | 2 | 2 |
| `write.listing_changes_enabled` | bool | false | true |
| `write.pms_max_ranges_per_post` | 1–100 | 100 | 100 |
| `write.call_timeout_seconds` | 10–300 | 120 | 300 |
| `write.max_dates_per_call` | 1–70 | 70 | 70 |
| `quota.per_listing` / `quota.window_hours` | 1–10 / 1–48 | 3 / 24 | 3 / 24 |
| `quota.per_account_per_minute` | 1–60 | 10 | 10 |
| `quota.account_wait_after_429_seconds` | 0–600 | 60 | 60 |
| `quota.future_slack_seconds` | 0–600 | 300 | 300 |
| `quota.failure_cooldown_minutes` | 0–1440 | 60 | 0 (the scheduled slot decides) |

The quota defaults are the provider's published limits (they are the provider's rules).

## 10. Deliberate divergences from the reference

| # | The plugin | The reference |
|---|---|---|
| C1 | Stops at the first 400/429/5xx/timeout of a write; the next run reads back before resending. | Same for recalculations; writes were retried by hand after reading the state. |
| C2 | A PMS 429 stops the run. | Waits for the credit window and retries once. |
| C4 | The journal reaches `stored` only by a read-back. | Registers after the API response. |
| C8 | Recalculation attempts recorded before the call (`pending`). | Recorded right after it. |
| C10 | Tonight's ceiling and deletion are checked by the gate (any ceiling raise tonight is refused, even below the current price: the conservative side). | Only "tonight does not rise" by the floor. |
| C11 | Attempts never carry the reference's tool-result ids (epoch milliseconds): the case "id in seconds is refused" of the recording golden does not apply. | Its ids come from the tool's results. |
| C13 | Listing changes are off by default and, when on, need a person's approval naming each one; never autonomous. | Written with the owner's order. |

## 11. Not here yet

- The run that drives this (collect → detect → decide → validate → gate → execute → report)
  inside the RunPipeline, and the wiring of `ConnectorFactory` (write routes, write authority, room
  units): integration.
- Live confirmation (recalculated calendar), delivery to the channel, the change log and undo,
  third-party change detection, the cancellation-wave hold: specified in 03-write-path.md sections
  4.3–8, not built in this area.
- `lead_time_expiry` (W36/W37): `DateOverride` does not read it yet, so a stored rule with an expiry
  is not turned into a group; the body builder never sends one.
- Channel limits are written but not read back (`InventoryNight` has no channel limits).
- The recalculation and listing-change routes and bodies are not in the verified API notes (D13).
