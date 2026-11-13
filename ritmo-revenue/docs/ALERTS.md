# Ritmo Revenue: alerts (milestone M3)

What every run watches, how alerts are deduplicated, reopened and resolved, and how people hear
about them. No alert, e-mail, table or log ever holds guest data: bookings appear by id, room,
dates, status, channel, origin and amounts only.

## 1. Where each piece is

| Folder | Classes |
|---|---|
| `src/Domain/Alerts` (pure) | `AlertCode` (catalog), `AlertSeverity`, `AlertLifecycle`, `AlertStatus`, `AlertDraft`, `AlertRecord`, `GuestDataGuard`, `AlertLifecycleRules`, `RaiseAction`, `NotificationPolicy`, `Delivery`, `AlertsConfig`, `SecurityConfig`, `BelowExpectedConfig`, `PriceBasis`, `SecurityWatch`, `SecurityFinding`, `SecurityFindingKind`, `BelowExpected`, `BelowExpectedFinding`, `ClosedWithoutBooking`, `ClosedNight`, `SecurityAlerts`, `InventoryAlerts`, `OperationAlerts` |
| `src/Application/Alerts` | `AlertService` (raise, settle, resolve, acknowledge), `AlertNotifier` (e-mail and digest), `MailText`, `WatchService`, `WatchInput`, `WatchOutcome` |
| `src/Infrastructure/Schedule` | `AlertLedger` (the `alerts` table with its lifecycle columns), `Mailer`, `WpMailer`, `RecipientDirectory`, `WpRecipientDirectory` |

## 2. Catalog

Event alerts end by a person; condition alerts end by themselves when a later run that checked
the same scope no longer finds them. The dedupe key is `code:property:scope`.

| Code | Severity | Lifecycle | Scope | Raised by |
|---|---|---|---|---|
| `security_unknown_origin` | critical | event | booking | `SecurityWatch` |
| `security_zero_value` | critical | event | booking | `SecurityWatch` |
| `security_zero_value_modified` | critical | event | booking | `SecurityWatch` |
| `security_cancellation_burst` | critical | event | channel + first cancellation | `SecurityWatch` (the waves also start the hold of price cuts, write path) |
| `booking_below_expected` | warning; critical under the critical threshold | event | booking | `BelowExpected` |
| `overbooked` | critical | condition | room + night | `InventoryAlerts::overbooked` |
| `inventory_closed_without_booking` | warning | condition | room + night | `ClosedWithoutBooking` |
| `inventory_stay_off_rule` | warning | condition | room + `stay` | `InventoryAlerts::audit` |
| `inventory_arrival_off_rule` | warning (other restrictions: info) | condition | room + `arrival` / `other_restricted` | `InventoryAlerts::audit` |
| `write_failed` | critical | event (resolved by `resolveKey` when the open groups complete) | provider | `OperationAlerts::writeFailed` |
| `recalc_quota_exhausted` | warning | condition | room | `LightRoundService` |
| `connector_down` | critical for the PMS ("vigilance blind"), warning for the pricing system | condition | provider | `MovementTracker` and the readers |
| `manual_change_detected` | warning | event (resolved when the freeze ends) | room + night + provider | `OperationAlerts::manualChange` from the third-party detection of the write path |
| `listing_changed_manually` | warning | event | listing | `OperationAlerts::listingChanged` |
| `run_late` | warning | condition | property | `SchedulerTick` |
| `run_missed` | warning | event | first missed slot | `SchedulerTick` |
| `scheduler_stalled` | warning | condition | site | `StallMonitor` |
| `vigilance_gap` | warning | event | start of the gap | `MovementTracker` |
| `sold_mismatch`, `push_disabled`, `push_stale` | warning | condition | room + night / listing | the measure stage (M1) |
| `approval_pending`, `approval_expired`, `mode_demoted` | info, info, critical | condition, event, event | plan / property | the approval and modes area |

## 3. The watch of a run (`WatchService`)

Every run, before any decision, over the one movement read and the inventory it read:

- **Security** (`SecurityWatch`, parity with `vigia_seguranca.classificar`, 17 goldens): a booking
  changed (or created, without a change instant) since the read's start; an origin outside the
  known list (non-empty, without case; only once the list is confirmed, `origins_confirmed`; until
  then the origins seen are listed for the owner); a confirmed or new booking without value,
  "modified" when created before the window; every burst of cancellations of one channel
  (`CancellationWave` of the write path, so the alert and the hold see the same waves).
- **Below expected** (`BelowExpected`, parity with `reservas_abaixo.avaliar`, 13 goldens):
  confirmed or new bookings changed since the read's start; expected = sum of the PMS prices of the
  nights x the channel's factor (property, then `*`, then the default factor); an alert when the
  unrounded ratio is under the threshold, critical under the critical threshold; sorted by the
  ratio. Off by default until the factors are set. The price is the one read last before the
  booking was made (`at_booking`, falling back to the price now) or the price now (`current`, the
  reference's way).
- **Overbooking**: every night with availability below zero.
- **Closed without booking**: `max(0, units - max(available, 0)) > booked`, or a blackout. These
  nights are handed to the plan as `RunInputs::$blocked` (never priced).
- The overbooking and closed-night conditions of the rooms and nights the run read and no longer
  finds are resolved; a room the run did not read keeps its alerts.
- `WatchService::audit` turns an inventory audit into one alert per room and kind of finding (at
  most `inventory_max_nights_listed` nights listed) and resolves the audit alerts of the audited
  rooms it no longer finds.

## 4. Lifecycle (`AlertService`, `AlertLifecycleRules`)

- A draft whose key matches an open or acknowledged alert refreshes it: message, facts, severity,
  run, the newest evidence and one more occurrence. No new row, no new e-mail.
- A resolved condition that comes back is a new row (a new episode). A resolved event comes back
  only when the draft's evidence instant is after the resolution.
- `settle(property, codes, raisedKeys, inScope)` resolves the open or acknowledged condition alerts
  of those codes the run checked (the scope closure limits it to the rooms and nights it read) and
  did not raise. A run that could not check calls nothing.
- `resolveKey` ends an alert that ends by itself (a run on time, the open groups of a failed write
  completed, a freeze ended, the connector read again).
- Acknowledge: security and write alerts by an approver, the others by anyone who sees them.
  Resolve by hand: approvers. The caller checks the capability and the nonce first.

## 5. Notification (`AlertNotifier`, `NotificationPolicy`)

- Critical: e-mailed at once, once per episode, within `notifications.max_emails_per_hour` per
  property (site setting); over the cap it waits for the digest.
- Warning: in the daily digest (`sendDigest`, after the day's full run).
- Information: never, except `approval_pending`.
- A connector still down after `connector_down_escalate_runs` runs is e-mailed once more.
- Recipients: the users who may approve and did not opt out (user meta `ritmo_alert_email` = `no`)
  plus `notifications.extra_addresses` (site setting, never in a preset).
- A failing mail is logged and left for the digest; it never fails the run that raised the alert.
- E-mails are plain text: code, property, room, night, the alert's facts and its id.

## 6. Privacy

`GuestDataGuard` refuses an alert whose facts carry a key naming guest data (name, guest, mail,
phone, document, address, note, comment, message, description...) or a free text longer than 200
characters. `BookingRecord` holds no guest data to begin with, and the rate description of the
reference is never read. The movement table (`booking_movements`) has no column for guest data.
The golden cases carry no names: properties and rooms are neutral markers.

## 7. Configuration (`alerts`; the operations spec nests it under `operations`)

| Key | Default | Parity |
|---|---|---|
| `security.enabled` | true | true |
| `security.known_origins` | `[]` | the owner's list (not in the fixtures) |
| `security.origins_confirmed` | false (learning) | true |
| `security.zero_value` | true | true |
| `below_expected.enabled` | false | true |
| `below_expected.threshold` / `critical_threshold` | 0.70 / 0.50 | 0.70 / 0.50 |
| `below_expected.default_factor` | 1.0 | 1.0 |
| `below_expected.price_basis` | `at_booking` | `current` |
| `below_expected.channel_factors` | `{}` | per property (fixture) |
| `inventory_max_nights_listed` | 50 | 50 |
| `connector_down_escalate_runs` | 3 | 3 |

The burst size and window are the write path's `waves` section (`min_cancellations`,
`window_hours`).

## 8. Golden cases

`tests/fixtures/tools/golden_ops.py` runs the reference's pure functions with frozen invented
inputs (no network, no bytecode in the engine folder, markers instead of the reference's ids and
names) and writes `tests/fixtures/golden/m3/ops/` (70 cases with its MANIFEST):
`vigia_seguranca.classificar` (17), `reservas_abaixo.avaliar` (13), `beds24.receita_por_noite` (8),
`beds24.movimento_de` (7), `beds24.alterada_desde` (5), `cota_recalculo.inicio_rodada` (10),
`cota_recalculo.proxima_rotina_completa` (10). Amounts within 0.01, ratios exact to 3 decimals,
kinds and ids exact.

## 9. Deliberate differences from the reference

| Difference | Why |
|---|---|
| Zero value is read from `BookingRecord::total` (invoice charges, or the price). | A price field of 0 with billed charges is not a booking without value. |
| The unknown-origin check is off until the owner confirms the list of origins. | A new client's list is unknown; the report lists the origins seen. |
| Bookings of rooms the property did not map are outside the watch. | Those rooms are outside the product. |
| Below expected keeps the booking's own channel and gives a channel without a factor the default factor; the reference writes "direct" for every channel but its two OTAs. | Same factor, but the owner sees the real channel. |
| Below expected may compare with the price at booking time. | A price raised after the sale made the reference's ratio drop without any error. |
| The channel's booking reference is not shown yet. | `BookingRecord` has no such field (operations spec D9, integration). |
| The rate description and the guest name are never read. | Free text typed by staff can hold a name. |
| Third-party changes are frozen and alerted, never restored automatically. | Rule 9(l) of the product; the reference re-closed a reopened closed day at once. |
