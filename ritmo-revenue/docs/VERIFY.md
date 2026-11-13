# Ritmo Revenue: confirmation, delivery, third-party changes, approval and cancellation waves (M3)

This document covers the part of the write path that comes after (and around) a write: what proves a write
was stored and is in force, when a pace step counts as delivered to the channels, how changes made by someone
else are found and frozen, how a plan waits for a person, and how a cancellation wave holds the price cuts. The
change log and undo have their own document, `docs/CHANGELOG-UNDO.md`. The gate, the schedule, the executor, the
journal, the resume and the recalculation quota belong to the write area (`src/Application/Write`).

Reference: the reference skill's `ler_recalculo.py` (confirmation), `pace.py` (`marcar_envio`,
`envios_da_coleta`), `beds24.py` (`_conferir`), `vigia_seguranca.py` (waves), `ordens.py` (`registrar_manual`),
`SKILL.md` and the learnings of the operation (AP dd/mm). Specs: 03-write-path (sections 4, 5, 7, 8) and
04-operations (sections 5.7, 5.8, 6).

| Folder | What is there |
|---|---|
| `src/Domain/Write/State` | `NightFields` (the values of a room night in one system), `ChangeField` (their names), `Provider`, `WriteKind`, `WrittenNight` (one night of an accepted write). |
| `src/Domain/Write/Confirm` | `StoredCheck`, `PmsWrite`, `PmsCalendarCheck`, `PmsCheckResult`, `LiveCheck`, `LiveVerdict`, `LiveStatus`, `LiveRoute`, `RuleLiveness`, `LivenessIssue`, `PercentSlack`, `IsolatedNight`. |
| `src/Domain/Write/Channel` | `PushTimes`, `ChannelArrival`, `ArrivalReport`. |
| `src/Domain/Write/Ledger` | `ConfirmedSteps`. |
| `src/Domain/Write/Change` | change log and undo (CHANGELOG-UNDO.md). |
| `src/Domain/Write/ThirdParty` | `Observation`, `ManualChangeDetector`, `ThirdPartyChange`, `DetectedFreeze`, `ThirdPartyConfig`. |
| `src/Domain/Write/Hold` | `CancellationWave`, `Wave`, `WaveHold`, `CancellationHold`, `HeldEntry`, `HoldResult`, `HoldScope`, `WaveConfig`. |
| `src/Domain/Write/VerifyConfig.php` | section `verify`. |
| `src/Application/Verify` | `Verifier`, `NightConfirmation`, `StoredStatus`, `ConfirmationQuota`, `RecordService`, `RecordOutcome`, `DeliveryService`, `UndoService`, `UndoRequest`, `ThirdPartyWatch`, `ThirdPartyResult`. |
| `src/Application/Approval` | `ApprovalService`, `ApprovalGrant`, `ApprovalKind`, `ApprovalStatus`, `ApprovalRefused`, `ApprovalConfig`, `ApprovalWindow`, `PlanEntry`, `PlanEntries`, `PartialApproval`, `ApprovalSelection`, `ApprovedSubset`, `RestrictedPlan`, `CapabilityCheck`, `WaveHoldService`. |
| `src/Infrastructure/Db` | `Migration/Migration0008Confirmation` and the repositories `ChangeLogRepository`, `WrittenStepRepository`, `PlanApprovalRepository`, `NightObservationRepository`, `DetectedFreezeRepository`, `WaveHoldRepository`. |
| `src/Domain/Plan/Validation` | the closures F2, X1 and 12 of the M2 review (section 7). |

## 1. Where this area sits in a write run

```
COLLECTED ─► DETECTED ─────────────► DECIDED ─► VALIDATED ─┬─► AWAITING_APPROVAL (ApprovalService)
             ThirdPartyWatch          CancellationHold       └─► GATED ─► WRITING ─► STORED_CHECKED ─► RECORDED ─► LIVE_CHECKED
             WaveHoldService.observe  (held entries listed)                         Verifier::stored   RecordService Verifier::live
                                                                                                         later runs: DeliveryService
```

- **DETECTED**: `ThirdPartyWatch::inspect()` compares the run's reads with the last observation of each night and
  freezes what someone else changed; its `orders()` join the run's `OrderBook`. `WaveHoldService::observe()` opens
  or widens the hold of a cancellation wave.
- **DECIDED**: `CancellationHold::apply()` removes the cuts while the hold is active; the held entries go to the
  journal and the report (never written, never in the ledger).
- **AWAITING_APPROVAL**: `ApprovalService::propose()`; the person approves; the approved write rebuilds the plan
  from fresh reads and `ApprovedSubset::restrict()` keeps only what was approved.
- **STORED_CHECKED / RECORDED**: `Verifier::stored()` then `RecordService::record()`: change log, step ledger and
  the read-back as the new observation, for stored nights only.
- **LIVE_CHECKED**: `Verifier::live()`, with the recalculation quota.
- **DELIVERED**: `DeliveryService::confirm()` in every later run that reads the listing directory and the PMS.

## 2. Stored: the read-back

A write is "stored" when the read-back holds what it should have left (`WrittenNight::intended()`):

| Write | What the night should hold | Compared |
|---|---|---|
| `write` (pricing) | what it held, with the sent fields written over (the pricing system merges a write into the date rule) | the fields the plugin writes: floor %, ceiling %, minimum stay, arrival and departure masks |
| `delete` | no date rule | any field left is `rule` |
| `rewrite` (group: delete, then write) | only the sent fields | the written fields plus the amounts and the expiry of the old rule |
| PMS | every sent field | availability against the expected NET value (units minus the night's bookings) when the write carries it; channel limits as text |

`NightFields` compares numbers within 1e-6 (percentages and stays are whole numbers) and texts exactly; the reason
text of a date rule is never part of it. `PmsCalendarCheck::compare()` is the block re-read of a PMS batch
(`beds24._conferir`, golden). A read that fails leaves the night `unread`: nothing is recorded and the next run's
resume reads it again. A mismatch is reported, never written again in the same run.

## 3. Live: the recalculated calendar

`LiveCheck::check()` on the recalculated night of the room:

| Check | Rule |
|---|---|
| In force | the override flag of the night; otherwise `inactive` (expired by lead time or out of reach: write it again as a group) |
| Floor in force | the calendar's floor within `max(1, 1.5 % of the floor)` of the written floor (`RuleLiveness`) |
| Price ≥ floor | `price ≥ floor − PercentSlack(floor, base)`; not judged on a sold-out night |
| Price ≤ ceiling | `price ≤ ceiling + PercentSlack(ceiling, base)`; not judged on a sold-out night |
| Minimum stay | equal; on an isolated night (free between two sold-out nights, `IsolatedNight`) a difference is the pricing system's orphan-day rule: warning, never rewritten |
| Deletion | no override in force |

`PercentSlack::of(v, base) = max(1.5 % of v, 1 % of max(v, base) + 1, 1)`. Floors and ceilings are compared as the
pricing system applies them, `base × (1 + pct/100)` in whole currency (decision D9).

`LiveRoute::for()`: a calendar recalculated at or after the write is compared at once (no quota); otherwise a
recalculation is spent only when `ConfirmationQuota::allows()` (the write area's quota) says so, and the
recalculation itself is a write step of the write path; without a slot the night is "not confirmed (recalculation
limit)" and a later run with a fresh calendar confirms it. Never a wait loop.

## 4. Delivered: push and PMS price; the step ledger

A written pace step counts as delivered only when **both** hold (`ChannelArrival`):

1. the room's last push (`PushTimes::fromListings()` over the listing directory: push off, unreadable instant or
   unmapped listing left out and said why; the same room twice keeps the oldest push) is at or after the step (a
   step stored to the minute only once its whole minute ended before the push);
2. the price the PMS holds for the night, read after that push, is the step's: a step up at least its value less
   `PercentSlack`, a step down at most its value plus `PercentSlack`.

Otherwise the step stays pending (`no_push_since_write`, `pms_price_unknown`, `pms_price_outside_step`) and the
cadence holds the next step of that night in the same direction; after `verify.delivery_overdue_hours` it is
overdue (an alert of the operations area).

The ledger the cadence reads is written only from confirmed writes: `ConfirmedSteps::records()` keeps the pace
steps whose night was confirmed stored and stamps them with the read-back instant; `WrittenStepRepository` stores
them with the journal row, marks them delivered with the push instant (`reached_channel_at`) and undone when an
undo of the night is confirmed (`undone_at`); `ledger()` leaves undone steps out (decision D10).

## 5. Changes made by others

The pricing API has no change log, so a change is found by comparing states (`ManualChangeDetector`):

- every read is an `Observation` of a room night (pricing date rule or PMS night), and so is every read-back after
  a write of the plugin (origin `written`): the plugin's own change is then the last observation, not a difference.
  A write merges into the rule that was there, so the written observation of a merged write keeps the night's other
  fields from its latest observation (a fixed price, say; a fixed floor or ceiling the write replaced by a
  percentage is dropped), and a deletion or a group's rewrite is kept as read (`RecordService`). Review finding of
  the M3 correction phase: the plugin's own merged writes were taken for manual changes and froze nights;
- a difference from the last observation is a third-party change, except: a first observation (baseline), a night
  before today, a night of a write of the plugin no run observed yet (a group left open, or a write whose unknown
  outcome the read-back of this run's watch found applied: `ResumeReport::ownUnobserved()`; the read-back runs
  before the comparison, in every mode, so a group written whole and changed by hand later is compared and frozen
  like any other night), PMS availability, a date
  rule gone after its lead-time expiry, a PMS stay or restriction equal to what the pricing system's push sets
  there, and the reason text (never observed);
- the changed nights of a room become a `DetectedFreeze`: exactly those nights, whatever their date, in both
  systems; it becomes manual owner orders (one per run of consecutive nights, because a manual order covers every
  night from its first to its last), so the engine stays away and the Validator refuses any item **or deletion**
  there (F2, section 7).

The freeze lasts `third_party.freeze_days` (7) from the detection, or, with `third_party.until_night_passes`, until a
person releases it or the night passes; a person can always release it earlier (`ThirdPartyWatch::release()`). `NightObservationRepository` keeps a
row only when the values of the night changed.

## 6. Plans waiting for a person

- `ApprovalService::propose()` stores a validated plan (sealed by the Validator) as a request: its entries
  (`PlanEntry`: kind, room, night or listing field, the exact fields sent as whole percentages, the order) and
  their hash (`PlanEntries::hash()`: SHA-256 of the canonical entries, never of explanations). Listing values go to
  a request of their own (`ApprovalKind::Listing`). A newer request of the property supersedes the older one, and
  lapses the approval of its kind not written yet.
- It expires at the property's next midnight or `approval.max_calendar_age_hours` after the calendar was read,
  whichever comes first (`ApprovalWindow`). An expired request is never approved nor written.
- `approve()` needs `ritmo_approve` at that moment (`CapabilityCheck`); a partial approval takes whole groups (a
  deletion and the item that writes its night again, `PartialApproval`); listing values must be named one by one
  (task rule 9(h)); two decisions cannot both win (conditional update).
- `grant()` gives the `ApprovalGrant` to the approved write: still in time and the approver still holds the
  capability. The write rebuilds the plan from fresh reads; `ApprovedSubset::restrict()` keeps only the approved
  entries with the same sent fields (a changed entry needs a new approval; a group only whole; a plan of another day
  keeps nothing); the result is validated again and goes to the write gate with the gate's `HumanApproval` built
  from the grant (`planHash` of the restricted plan, `today` = the grant's day, `approvedBy`,
  `listingChanges()`).
- At most one approval of a kind waits for its write: approving lapses the older approved request of the kind,
  and the approved write takes the request decided last (never an older one left behind).
- `markWritten()`, `expireDue()`, `expireAll()` close the life of a request; `expireAll()` (any change of mode, the
  emergency switch engaged, the rules changed) expires the pending requests and lapses the approved ones not
  written yet: an approval never writes after the change it predates.

## 7. Validator closures of the M2 review

| # | Finding | Now | Code | Divergence |
|---|---|---|---|---|
| F2 | Deleting the date rule of a night frozen by a manual change passed. | `DeletionCheck` asks `AuthorityCheck` for every deletion: refused with `night_frozen`. | `Validation/DeletionCheck.php`, `Validation/AuthorityCheck.php` | The reference checks the freeze on items only. |
| X1 | An engine item on a night whose price an owner order fixed passed. | Any item or deletion on such a night is refused with `night_fixed_by_order`, unless it cites that very order. Listing changes are not concerned. | `Validation/AuthorityCheck.php` (`NIGHT_FIXED_BY_ORDER`) | The reference engine only stays away from the night; its validator does not look. |
| 12 | After refusals, the order between related rooms was not checked again. | `RelationOrderCheck` (when the context carries the relations, `ValidationContext::$relations`): `at_least` target not below its reference plus the margin, `between` (path) target not above its dearer reference, with each room at the floor of its item or its current price. Broken by the plan beyond what it was before: the target's item is refused, or, without one, the references' items; validating again without them converges. Exempt: out of scope, a pair the coherence stage warned about, a sold-out target, an `at_least` reference with fewer free units than asked. | `Validation/RelationOrderCheck.php` (`RELATION_BROKEN`) | The reference does not check it either. |

Tests: `tests/Unit/Domain/Plan/Validation/OrderGuardsTest.php`, `RelationOrderCheckTest.php`,
`tests/Unit/Domain/Write/ThirdPartyTest.php` (a detected freeze refuses items and deletions).

## 8. Cancellation waves

`CancellationWave::detect()` (golden): per channel, every cancellation inside a window holding
`waves.min_cancellations` within `waves.window_hours` is marked and the marked ones are grouped while each is at
most the window after the previous; bookings modified before the instant, and cancellations before it, do not count;
every wave of the run, not only the first. `WaveHold` keeps the first and last cancellation and the room nights of
the cancelled bookings (bookings by PMS id only). While active (until a person with `ritmo_approve` releases it, or
`waves.hold_hours` after the last cancellation when set), `CancellationHold::apply()` removes the cuts:

- an item whose applied floor is under the floor in force (unknown floor in force: held), or whose ceiling is under
  the current price;
- a deletion not followed by a rewrite that keeps the floor in force; a deletion and its rewrite together;
- a listing value lowered;
- on the PMS (`holdsPms()`), a change that opens sales on a night of the cancelled bookings (restriction lifted,
  shorter stay, more units, channel limit raised).

Raises, protections, closings and stay increases go ahead. The scope of the price cuts is the whole property by
default (`waves.scope`, decision D11).

## 9. Configuration

| Key | Range | Default (neutral) | Parity (`tests/fixtures/presets/parity-verify.json`) |
|---|---|---|---|
| `verify.delivery_overdue_hours` | 1–72 | 30 | 30 |
| `third_party.until_night_passes` | bool | false | false |
| `third_party.freeze_days` | 1–60 | 7 | 7 |
| `waves.min_cancellations` | 2–20 | 3 | 3 |
| `waves.window_hours` | 1–24 | 3 | 3 |
| `waves.hold_hours` | null or 1–168 | null (until a person releases it) | null |
| `waves.scope` | `property`, `cancelled_nights` | `property` | `property` |
| `approval.max_calendar_age_hours` | 1–24 | 6 | 6 |

The tolerances of the live check (1.5 %, one unit, `PercentSlack`) are measurements of the pricing system's own
rounding, not business rules: they are constants with the reference's values.

## 10. Golden cases

Generator: `tests/fixtures/tools/golden_verify.py` (imports the reference read-only, no bytecode, network blocked,
data files pointed at a temporary folder, the clock always an input). Cases in `tests/fixtures/golden/m3/verify/`
with their `MANIFEST.json`; test `tests/Unit/Domain/Write/VerifyGoldenTest.php`. Amounts within 0.01, verdicts and
counts exact.

| File | Cases | PHP |
|---|---|---|
| `ler_recalculo.regra_inativa.json` | 9 | `RuleLiveness::check()` |
| `ler_recalculo.tolerancia_percentual.json` | 6 | `PercentSlack::of()` |
| `ler_recalculo.dia_isolado.json` | 6 | `IsolatedNight::is()` |
| `pace.envios_da_coleta.json` | 4 | `PushTimes::fromListings()` |
| `beds24._conferir.json` | 6 | `PmsCalendarCheck::compare()` |
| `vigia_seguranca.classificar.waves.json` | 6 | `CancellationWave::detect()` |

The M2 golden `pace.marcar_envio` is reused: with the PMS price at the step's value, `ChannelArrival` delivers
exactly the steps the reference marks.

## 11. Where the plugin decides differently

| # | The plugin | The reference |
|---|---|---|
| C3 | A step is delivered only with the push **and** the PMS price of the step. | The push instant alone (`pace.marcar_envio`). |
| C4 | Change log, step ledger and observations at "stored" (read-back). | After the API response. |
| C5 | A detected change freezes exactly its nights, whatever their date, for N days (default 7) or until released. | Nights capped at today + 7 (`ordens.registrar_manual`). |
| C7 | Third-party changes by comparing states. | By the pricing system's user log (`api_` prefix). |
| C9 | The live floor is compared with the applied amount `base × (1 + pct/100)`. | With the plan's raw floor. |
| D8 | A third-party change is frozen and shown, never written back automatically. | A required PMS restriction was written back the same day. |
| F2, X1, 12 | Section 7. | Not checked. |
| H1 | A wave holds the cuts in the plan until a person releases the hold (or the configured hours pass). | An alert; the operator held the cuts by hand. |

## 12. Storage (migration 8, `Migration0008Confirmation`)

| Table | New |
|---|---|
| `change_log` | `group_key`, `undo_of`, `confirm_level`, `order_id`, `old_amount`, `new_amount`, index `run` |
| `step_ledger` | `reached_channel_at`, `undone_at`, `write_ref`, index `pending` |
| `plan_approvals` | the requests: kind, status, plan hash and day, ruleset version, calendar read and expiry, entries, decision (approved and rejected keys, user, capability, note), the write run |
| `night_observations` | per provider, room and night: fingerprint, fields (JSON), origin, run, instant |
| `detected_freezes` | room, nights, fields, detected, frozen until, released (by whom) |
| `wave_holds` | since, last cancellation, nights, booking ids, released (by whom) |

No column can hold guest data.
