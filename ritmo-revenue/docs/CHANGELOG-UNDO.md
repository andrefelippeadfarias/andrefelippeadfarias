<![CDATA[# Ritmo Revenue: change log and undo (M3)

Every change the plugin makes is recorded with what the night held before, what it holds after, why, who allowed
it and how to undo it. An undo is never a direct write: it is a plan, built from what the nights hold now, that
goes through the Validator and the write gate with the approval of the person who asked for it.

Reference: `SKILL.md` (the change log with "Mudança | Onde | Antes | Depois | Como desfazer"), the merge semantics
of the pricing system (AP 14/10: a write merges into the date rule; a field added is removed only by deleting the
rule), spec 03-write-path section 5. Code: `src/Domain/Write/Change`, `src/Application/Verify/RecordService.php`,
`src/Application/Verify/UndoService.php`, `src/Infrastructure/Db/Repository/ChangeLogRepository.php`.

## 1. One row per field changed (`ChangeRecord`, table `change_log`)

`ChangeRecorder` turns a write confirmed STORED (the read-back, never the API response alone) into one row per field
whose value changed between what the night held when the run collected (`WrittenNight::$before`) and the read-back:

| Column | Content |
|---|---|
| `run_id`, `property_code`, `room_code`, `night`, `provider` | where (`pricing`, `pms`, `listing`; a listing value has no night) |
| `field` | `floor_pct`, `ceiling_pct`, `min_stay`, `check_in_mask`, `check_out_mask`, `rule` (presence of the date rule), the amounts a third party had set (`floor_fixed`, `ceiling_fixed`, `price`, `price_type`), `pms_min_stay`, `pms_max_stay`, `pms_restriction`, `pms_available`, `pms_channel_limit:<channel>`, `listing_min`, `listing_base`, `listing_max` |
| `old_value`, `new_value` | the values (JSON, typed) |
| `old_amount`, `new_amount` | for a percentage, its amount over the base of the day it was written ("floor 1,051 (−29 %)" reads the same after the base changes) |
| `reason` | the reason code and text of the plan entry; `order_id` the owner order it cites |
| `undo_plan` | the undo of the night (section 2) |
| `actor` | `autonomous`, `system` or `user:<id>` (the approver, or who asked for the undo) |
| `group_key` | the group of a deletion and its rewrite |
| `confirm_level` | `stored`, or `live` when the recalculated calendar already confirmed it |
| `undo_of`, `undone_at` | the row an undo undid; when this row was undone |

On the PMS only the fields the plugin sent count (availability also moves with bookings). A write that changed
nothing leaves no row. Rows are never deleted.

## 2. The undo of a night (`UndoAction::inverse()`)

The pricing system merges the fields of a write into the date rule, so removing a field needs a deletion:

| Before → after | Undo (`UndoKind`) |
|---|---|
| rule F → same fields, some values changed | `write_fields`: the old values of the changed fields |
| rule F → a field added (e.g. a ceiling) | `delete_then_write`: delete the date, write all of F again |
| no rule → rule | `delete` |
| rule F → no rule | `write_fields`: all of F |
| PMS field x → y | `write_fields` with x (only fields the plugin wrote; never a price, never a booking) |
| listing value | `listing_change` back (a listing change: an order releasing the listing and a human approval, like any) |
| nothing changed | `nothing` |

## 3. Undo is a plan through the Validator (`UndoPlanner`, `UndoService`)

1. Only a user holding `ritmo_approve` may ask (`UndoService::request()`).
2. What the nights hold NOW is read (date rules, PMS nights, listing values).
3. `UndoPlanner::plan()` builds an `UndoDraft` per night:
   - `undo_conflict`: a field no longer holds the value the plugin left (changed since by someone else or a
     later run): left out and shown, **never overwritten**;
   - `already_undone`, `past_night`, `unknown_room`, `nothing_to_undo`;
   - `several_changes`: the rows of one night come from more than one change: undo the latest first;
   - `not_restorable`: a value the plugin never writes (a price, a lead-time expiry);
   - percentages are written again as the same whole percentage of today's base; an amount a third party had set
     is restored as the nearest whole percentage, and the draft's notes say so (decision D15);
   - PMS fields become `PmsRestore` entries beside the plan; listing values become listing changes.
4. The draft goes through the Validator (tonight never rises: an undo that would raise tonight is refused; safety
   floors; owner orders and freezes). What it refuses is dropped and listed; the rest is validated again.
5. What passes is proposed (`ApprovalKind::Undo`, listing values apart) and approved by the person who asked:
   `UndoRequest` carries the grants. The approved write rebuilds and restricts it like any approved plan.
6. Once the undo is written and confirmed stored, `UndoService::confirm()` marks the rows undone and the steps
   their runs wrote on those nights undone too: the cadence stops counting them (decision D10). The undo's own rows
   are recorded by `RecordService` like any change.

An undo never touches a booking and never writes a price to the PMS.

## 4. Tests

`tests/Unit/Domain/Write/ChangeLogTest.php` (rows, amounts, the inverse table), `UndoPlannerTest.php` (conflicts,
groups, percentages, PMS and listing, an undo that would raise tonight refused by the Validator),
`tests/Unit/Application/Verify/RecordAndUndoTest.php` (only stored nights recorded, approval by the person who asked,
conflict, confirmation marks rows and steps), `StorageTest.php` (round trip of a row).
]]>