# Ritmo Revenue: scheduler and light runs (milestone M3)

This document covers how runs start (the scheduler) and what a light run does. The alerts are in
`ALERTS.md`; writing, confirmation and the recalculation quota belong to the write path
(`VERIFY.md` and the write area's own document).

## 1. Where each piece is

| Folder | Classes | What they do |
|---|---|---|
| `src/Domain/Operations` (pure) | `ScheduleConfig`, `LocalTime`, `Slot`, `SlotKind`, `SlotStatus`, `SlotPlanner`, `DueSlotPolicy`, `SlotDecision`, `SlotRecord`, `PastRun`, `RunKind`, `OperationState` | The schedule of a property, its slots in the property's timezone, and the choice among due slots. |
| `src/Domain/Operations` (pure) | `LightRunConfig`, `MovementWindow`, `MovementClassifier`, `MovementDetector`, `MovementBatch`, `Movement`, `MovementKind`, `BookingVersion`, `SoldOutFlip`, `SoldOutFlips`, `LightRoundFacts`, `LightRoundPlanner`, `LightRoundPlan`, `LightTrigger`, `DeferredWork`, `OrphanWindow` | The light run: what moved, which nights, which rooms may take a step, what waits for the next full run. |
| `src/Application/Schedule` | `SchedulerTick`, `RunLauncher`, `LockedRunLauncher`, `RunExecutor`, `RunNowService`, `LaunchResult`, `LaunchStatus`, `FailureKind`, `ScheduleSource`, `ScheduleHousekeeping`, `TickReport`, `PauseService`, `StallMonitor`, `MovementTracker`, `MovementRead`, `LightRoundService`, `LightRoundRequest`, `RoomQuota` | The tick, the property run lock, "Run now", the daily purge, the pause, the stalled-scheduler check, the movement read and the scope of a light run. |
| `src/Infrastructure/Schedule` | `ScheduledRunRepository`, `OperationStateRepository`, `BookingMovementRepository`, `WpCronTrigger` | Slot rows, per-property state, processed booking versions, the WP-Cron event. |
| `src/Infrastructure/Db/Migration/DraftOps.php` | | Draft migration (the integration numbers it): `scheduled_runs`, `operation_state`, `booking_movements`, and the lifecycle columns of `alerts`. |

## 2. The scheduler

### 2.1 Technology: the plugin's own scheduler on WP-Cron

One WP-Cron event, `ritmo_tick`, every five minutes (interval `ritmo_five_minutes`,
`WpCronTrigger`). Everything else is the plugin's: slot rows, the choice among due slots, locks,
resumable runs. Action Scheduler was considered and left out: the last line that runs on WordPress
6.5 (3.9.x) lacks the later security fixes, and when several plugins bundle it the newest copy of
another plugin is the one that runs; it would not remove any of the domain rules below either.
WP-Cron needs traffic: on a quiet site, or with `DISABLE_WP_CRON`, a system cron must call
`wp-cron.php` (or `wp cron event run ritmo_tick`) every five minutes. `StallMonitor` raises
`scheduler_stalled` when a slot has waited longer than `stalled_after_minutes` and no tick ran.

### 2.2 Slots

A slot is a configured local time of a property: one full time and up to eight light times
(`schedule` section). `SlotPlanner` turns them into instants in the property's timezone. A time in
a spring-forward gap falls on the first valid instant after it (02:30 in a 02:00 to 03:00 gap is
03:00); a time that repeats when the clocks go back falls on its earlier instant and is one slot
(the key is the local date-time and the kind). `latestAtOrBefore` (inclusive) and `nextFullAfter`
(strict) equal the reference's `inicio_rodada` and `proxima_rotina_completa` on the parity
schedule (goldens). The write path takes the run start and the next full run of its quota from
them.

### 2.3 The tick (`SchedulerTick::run`)

1. Store the heartbeat (`scheduler_last_tick`), take the site tick lock (`scheduler_tick`); held:
   return busy. Resolve `scheduler_stalled`. The tick never throws: a broken tick (the database,
   the lock table) is logged with the exception class and reported as `failed`.
2. For each property, oldest due slot first, while the tick budget lasts:
   1. Lift a pause whose end passed.
   2. Record the slots that came due since the last tick (`scheduled_runs`, unique per property,
      local date-time and kind: two ticks never create two rows). A property seen for the first
      time starts at its local midnight; after a long stop at most 31 days are recorded.
   3. A run in progress (any kind) is continued through `RunLauncher::continueOpen` (stopped at
      its next stage boundary through `stopOpen` when the property is paused) and nothing else
      starts for that property in this tick; while another request holds the property's run lock
      the property waits. A run of an earlier local day is closed as failed instead and the tick
      goes on to the new day's slots. A slot whose run ended outside the scheduler is closed with
      the run's status.
   4. Under the emergency switch with `stop_reads_under_emergency`, every due slot ends
      `skipped_blocked`.
   5. `DueSlotPolicy::choose` decides the slot to run and closes the others (2.4).
   6. `RunLauncher::start` runs it (forced shadow under the emergency switch). The launcher holds
      the property's single run lock: when another run holds it the answer is busy and the slot
      stays due. The slot records the run, the start, the delay and whether it was promoted.
   7. A run that ends records its final status on the slot; a failure because a system could not
      be reached gets one `retry` slot (2.5). Late starts raise `run_late`, missed days
      `run_missed`.
3. An exception of one property is logged with its class only, fails that slot and never stops
   the others.
4. The first tick of each UTC day purges (`ScheduleHousekeeping`): slot rows that ended more than
   90 days ago (a due or running slot stays) and the booking versions of bookings that departed
   more than 8 days ago (before "today - 7" in every timezone).

### 2.3.1 No overlap: the property run lock (`LockedRunLauncher`)

- One lock key per property for every kind of run, `property_run:<id>` (TTL 600 s), taken around
  every piece of work: starting a run, continuing it for one budget, stopping it. The scheduler and
  "Run now" (`RunNowService`) go through the same launcher, and so does the write of an approved
  plan (`approved_write`).
- Held by another request: the answer is busy (the slot stays due, a person sees "busy"); nothing
  is queued.
- Between two requests the open run itself excludes a new one: a run starts only when the property
  has no run in progress of any kind (the read-only milestone's `shadow` kind included); otherwise
  the open run is continued.
- A request that died leaves the lock to expire after its TTL; the next tick then resumes the open
  run from its next stage through `RunExecutor::resume`, never from the start (S8).
- The work itself is a `RunExecutor` (the run pipeline of each kind, integration): it never takes
  the lock and calls the heartbeat it is given before each stage and each write slice; a false
  answer (the lock was lost) stops it at once.
- An open run of an earlier local day is closed as failed (`FailureKind::DayChanged`) and never
  continued: its plan is never written.
- "Run now" (`RunNowService`) runs a paused property too (a pause stops only scheduled slots, S11),
  continues a run in progress instead of starting a second one, starts a `manual` run otherwise,
  and is a forced shadow run under the emergency switch (`stop_reads_under_emergency` applies to
  the scheduled reads only).
- `WpCronTrigger::spawnNow()` asks WordPress to run the due cron events at once (decision D16): the
  approval of a plan calls it so the `approved_write` slot starts without waiting five minutes,
  still under the property's lock.

### 2.4 Which slot runs (`DueSlotPolicy`)

1. Paused: every due slot `skipped_paused`; nothing starts.
2. A slot of a local day before today: `skipped_missed_day`; the first and last of them are the gap
   of the `run_missed` alert (one per gap).
3. A due `approved_write` slot runs first; the scheduled slots wait for the next tick.
4. Covered: a run that started at or after the slot and completed already read what the slot
   would read (`skipped_covered`). A full slot is covered only by a full or manual run; a write run
   covers nothing.
5. Of the remaining slots of today the latest runs; the others are `skipped_coalesced` (a light
   run reads the movement since the last successful read, so one run covers every missed slot).
6. The chosen slot runs full when it is the full slot, or when no full (or manual) run completed
   on today's local date: the first run of a day is always full (`promoted`). A light time before
   the full time therefore runs full, and the full slot still runs at its time (the schedule
   validation warns about it).
7. Late: started more than `late_tolerance_minutes` after its time. It still runs.

A slot in the future is never started; a clock moved back never runs a slot twice.

### 2.5 Day change, retries, pause

- A run's day is fixed when it starts; an open run of an earlier local day is closed as failed by
  the launcher and its plan is never written (`FailureKind::DayChanged`, no retry).
- `retry_failed_after_minutes` after a run of a full or light slot failed for a connector reason,
  one `retry` slot, only when the next configured slot is more than twice that away. A failed retry
  is not retried; a run that failed while writing is never retried (the next slot reads again).
- `PauseService` (capability "manage rules", checked by the caller with the nonce): while paused no
  scheduled slot starts; "Run now" stays available; the run in progress stops at its next stage
  boundary, never inside a write slice. A pause with an end is lifted by the first tick after it.

### 2.6 Configuration (`schedule`; the operations spec nests it under `operations`)

| Key | Default (neutral) | Parity | Meaning |
|---|---|---|---|
| `full_time` | `07:30` | the reference's full routine | Local time of the daily full run. |
| `light_times` | `[]` (no light runs) | the four light routines | Local times of the light runs, up to 8. |
| `min_spacing_minutes` | 60 | 60 | Every two times at least this apart (across midnight too). |
| `late_tolerance_minutes` | 30 | 30 | Later starts raise `run_late`. |
| `retry_failed_after_minutes` | 15 | 15 | One retry of a connector failure; 0 = never. |
| `run_budget_seconds` | 20 | 20 | Work per property per tick. |
| `tick_budget_seconds` | 40 | 40 | Work per tick. |
| `max_movement_lookback_days` | 30 | 30 | Cap of the movement read after a stop (beyond: `vigilance_gap`). |
| `stalled_after_minutes` | 20 | 20 | `scheduler_stalled` threshold. |
| `stop_reads_under_emergency` | false | false | Under the emergency switch scheduled runs still read and watch as shadow runs; true stops them. |

Parity values: `tests/fixtures/presets/parity-ops.json`.

## 3. The light run

### 3.1 Movement (`MovementTracker`, `MovementDetector`)

- The read starts at the cursor (the instant just before the last successful read) minus
  `movement_overlap_minutes`. Without a cursor the first read covers `initial_lookback_hours` and
  is a baseline: every booking is recorded, nothing moves. The cursor moves only after a
  successful read.
- A failed read is tried once more after ten seconds (not after a rate limit, which the client
  already waited, nor after a refused connection). A second failure raises `connector_down`
  (critical, "vigilance blind") and the run decides nothing.
- Every processed version of a booking (fingerprint of status, room, dates, units, total and PMS
  timestamps) is kept per property in `booking_movements`: a version seen again in the overlap is
  never movement again. A changed version carries the previous one, so a change of dates or room
  frees the old nights.
- Without a previous version the reference's classification applies (`MovementClassifier`,
  golden); with one, the status transition decides (a booking that became cancelled is a
  cancellation even without a cancellation instant).
- Bookings of rooms the property did not map are only counted.

### 3.2 Scope (`LightRoundService`, `LightRoundPlanner`)

The run goes on to decide when something moved on a night of the horizon, a night from today to
`orphan_window_days` sold out or reopened (compared with the latest inventory read before this
run), a manual change was found, or a new night is overbooked. Otherwise it ends with the
three-line report.

The light filter (`RunInputs::$lightFilter`, PlanItem keys):
- the nights of the bookings that moved, in `[today, today + horizon)`, both the new and the
  previous version of a modified booking (a blocked period is not a sale);
- the nights from today to `protect_flip_days` that sold out or reopened, even without a booking;
- nothing else: manual-change nights are frozen, related rooms are re-evaluated by the engine, the
  orphan stays have their own plan.

A room takes a pace or ladder step only when the recalculation that confirms the write fits the
quota (`RoomQuota`, asked without recording anything). A room without quota keeps only the
protection of its nights sold out now; its other nights wait for the next full run and it raises
`recalc_quota_exhausted` ("not confirmed (recalculation quota)" in the report), resolved when it
has quota again.

`OrphanWindow`: the rooms with a flip from today to `orphan_window_days` are audited again over
that window with the inventory read now (`InventoryAudit` and `StayBatch` of M2): the PMS stays and
the closed-day orphan side open and close back at once; entries flagged `alsoPricing` go to the
pricing system too; a frozen night gets nothing.

What waits for the next full run (`DeferredWork`, `LightRoundPlan::$deferredKeys`): pace steps of
nights without a new sale and of rooms without quota, protection of sold-out nights outside the
filter, the relations sweep, the stay plan beyond the window, anchors, listing settings (never in a
light run), the weekly review.

### 3.3 Configuration (`light_run`)

| Key | Default | Parity | Meaning |
|---|---|---|---|
| `movement_overlap_minutes` | 15 | 5 | Margin before the cursor. |
| `initial_lookback_hours` | 24 | 24 | The first read of a property. |
| `protect_flip_days` | 1 | 1 | Flips from today to this day enter the filter. |
| `orphan_window_days` | 7 | 7 | Flips from today to this day re-plan the orphan stays. |
| `read_rules_without_movement` | false | false | Read the date rules even when nothing moved. |
| `read_listing_status` | true | true | One read of the listing directory per light run. |

## 4. Deliberate differences from the reference

| Difference | Why |
|---|---|
| The schedule is configuration; the neutral default is one full run a day and no light runs. | The reference has the owner's five routines as constants; the parity preset carries them. |
| A property seen for the first time records only the slots of its current local day. | The days before it existed in the scheduler were not missed: no `run_missed` on the first tick. |
| Instants, not ISO strings, in the movement comparisons. | Strings of different precision sort wrongly. |
| With a stored previous version a status transition decides the kind (confirmed to cancelled is a cancellation). | The reference has no previous version and calls it "modified"; the golden case stays with the reference (no previous version). |
| A booking version already processed is never movement again. | The reference's overlap counted bookings twice (lesson of 08/11). |
| Sold-out flips near today enter the filter without a booking movement. | A block or a room move changes availability without a sale. |
| No recalculation before deciding; the quota is checked for the confirmation after writing. | Sales are counted from the PMS; a mutating call before a validated plan would need a token. |
| A light run with the PMS down stops with "vigilance blind"; no plan B through the pricing system. | The pricing system has no booking read in the plugin's contracts. |

## 5. For the integration

- Number `DraftOps` and add it to `Migrations::all()`; name the new tables in INFRASTRUCTURE.md;
  regenerate the signature references (`php bin/sync-generated.php`).
- Wire `ritmo_tick` (`WpCronTrigger::withInterval` on `cron_schedules`, `schedule()` on activation,
  `unschedule()` on deactivation, the hook calling `SchedulerTick::run`), replacing the hourly
  resume hook; add `WpCronTrigger`, `WpMailer` and `WpRecipientDirectory` to the reviewed list of
  `InfrastructureRulesTest`.
- Implement `RunExecutor` over the run pipeline (kinds `full`, `light`, `manual`,
  `approved_write`; the light stages `collect_light`, `watch`, `scope` using `MovementTracker`,
  `WatchService` and `LightRoundService`) and give it to `LockedRunLauncher`, which already holds
  the property's run lock and closes runs of an earlier day; make "Run now" (REST and admin) call
  `RunNowService`, and make `ShadowRunService` take `LockedRunLauncher::lockKey()` instead of
  `shadow_run:<id>` (or retire it behind the executor), so that no run of a property bypasses the
  lock. Implement `ScheduleSource` and the sections `schedule`, `light_run`, `alerts` from the rule
  set, `RoomQuota` over the write path's `RecalcQuota`.
- Call `WpCronTrigger::spawnNow()` after an approval is recorded (D16).
- Record pause, resume and the hold in `change_log` (O41) once the change log of the write path is
  in place.
