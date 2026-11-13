<?php

declare(strict_types=1);

namespace Ritmo\Domain\Write\Change;

use Closure;
use DateTimeImmutable;
use Ritmo\Domain\Model\RoomType;
use Ritmo\Domain\Plan\ListingChange;
use Ritmo\Domain\Plan\ListingField;
use Ritmo\Domain\Plan\Plan;
use Ritmo\Domain\Plan\PlanDeletion;
use Ritmo\Domain\Plan\PlanItem;
use Ritmo\Domain\Write\State\ChangeField;
use Ritmo\Domain\Write\State\NightFields;
use Ritmo\Domain\Write\State\Provider;

/**
 * Builds the undo of change-log records as a plan draft, from the state read NOW:
 *
 * - a night whose fields are no longer the values the plugin left (someone changed it since) is
 *   left out as `undo_conflict`, never overwritten;
 * - a night with no rule before becomes a deletion; a rule that gained a field becomes a deletion
 *   and the old rule written again; otherwise the old values of the changed fields are written;
 * - percentages are written again as the same whole percentage of today's base; an amount a third
 *   party had set is restored as the nearest whole percentage, and the draft says so (decision D15);
 * - PMS fields become restores beside the plan; listing values become listing changes (which need
 *   an order releasing the listing and a human approval, like any listing change).
 *
 * The draft is NOT a validated plan: the caller runs it through the Validator (tonight never
 * rises, safety floors, orders) and the write gate with the approval of the person who asked.
 */
final class UndoPlanner
{
    public const REASON = 'undo';

    private const LISTING_FIELDS = [
        ChangeField::LISTING_MIN => ListingField::Min,
        ChangeField::LISTING_BASE => ListingField::Base,
        ChangeField::LISTING_MAX => ListingField::Max,
    ];

    /** @var list<PlanItem> */
    private array $items = [];

    /** @var list<PlanDeletion> */
    private array $deletions = [];

    /** @var list<ListingChange> */
    private array $listing = [];

    /** @var list<PmsRestore> */
    private array $restores = [];

    /** @var list<UndoSkip> */
    private array $skipped = [];

    /** @var list<string> */
    private array $notes = [];

    /** @var list<int> */
    private array $undoOf = [];

    /**
     * @param list<ChangeRecord>          $records the rows to undo
     * @param array<string, NightFields>  $fresh   ChangeRecord::subjectKey() => what the night (or listing) holds now
     * @param array<string, RoomType>     $rooms   room code => room type of the property
     */
    public function plan(array $records, array $fresh, array $rooms, string $propertyId, DateTimeImmutable $today, DateTimeImmutable $now): UndoDraft
    {
        $this->items = $this->deletions = $this->listing = $this->restores = $this->skipped = $this->notes = $this->undoOf = [];
        $bySubject = [];
        foreach ($records as $record) {
            $bySubject[$record->subjectKey()][] = $record;
        }
        ksort($bySubject);
        foreach ($bySubject as $key => $group) {
            $this->subject($key, $group, $fresh[$key] ?? NightFields::none(), $rooms, $today);
        }

        return new UndoDraft(
            new Plan($propertyId, $today, $now, $this->items, $this->deletions, $this->listing),
            $this->restores,
            $this->skipped,
            $this->undoOf,
            $this->notes,
        );
    }

    /**
     * @param list<ChangeRecord>      $group
     * @param array<string, RoomType> $rooms
     */
    private function subject(string $key, array $group, NightFields $now, array $rooms, DateTimeImmutable $today): void
    {
        $first = $group[0];
        $ids = array_values(array_filter(array_map(static fn (ChangeRecord $r): ?int => $r->id, $group), static fn (?int $id): bool => $id !== null));
        $skip = fn (string $reason, array $fields = []) => $this->skipped[] = new UndoSkip($reason, $key, $first->roomCode, $first->night, $fields, $ids);
        $room = $rooms[$first->roomCode] ?? null;
        $actions = array_unique(array_map(static fn (ChangeRecord $r): string => (string) json_encode($r->undo->toArray()) . '|' . $r->createdAt->format('U'), $group));
        if (array_filter($group, static fn (ChangeRecord $r): bool => $r->isUndone()) !== []) {
            $skip(UndoSkip::ALREADY_UNDONE);
        } elseif ($first->night !== null && $first->night < $today) {
            $skip(UndoSkip::PAST_NIGHT);
        } elseif ($room === null) {
            $skip(UndoSkip::UNKNOWN_ROOM);
        } elseif (count($actions) > 1) {
            $skip(UndoSkip::SEVERAL_CHANGES);
        } elseif (($changed = $this->changedSince($group, $now)) !== []) {
            $skip(UndoSkip::CONFLICT, $changed);
        } else {
            $this->undo($first, $room, $ids, $skip);
        }
    }

    /**
     * The fields whose value now is not the one the plugin left.
     *
     * @param list<ChangeRecord> $group
     *
     * @return list<string>
     */
    private function changedSince(array $group, NightFields $now): array
    {
        $changed = [];
        foreach ($group as $record) {
            $current = $record->field === ChangeField::RULE
                ? ($now->of(Provider::Pricing)->isEmpty() ? ChangeRecord::ABSENT : ChangeRecord::PRESENT)
                : $now->get($record->field);
            if (!NightFields::same($record->newValue, $current)) {
                $changed[] = $record->field;
            }
        }

        return $changed;
    }

    /**
     * @param list<int> $ids
     */
    private function undo(ChangeRecord $record, RoomType $room, array $ids, Closure $skip): void
    {
        $action = $record->undo;
        if ($action->kind === UndoKind::Nothing) {
            $skip(UndoSkip::NOTHING_TO_UNDO);

            return;
        }
        $this->undoOf = [...$this->undoOf, ...$ids];
        $text = sprintf('Undo of change %s', implode(', ', $ids));
        if ($record->provider === Provider::Listing) {
            foreach ($action->fields->all() as $field => $value) {
                $this->listing[] = new ListingChange($room->code, self::LISTING_FIELDS[$field], (float) $value, self::REASON, $text, $record->orderId);
            }

            return;
        }
        if ($record->provider === Provider::Pms) {
            $this->restores[] = new PmsRestore($room->code, $record->night, $action->fields, $ids);

            return;
        }
        if ($action->kind !== UndoKind::WriteFields) {
            $this->deletions[] = new PlanDeletion($room->code, $record->night, self::REASON, $text);
        }
        if ($action->kind !== UndoKind::Delete) {
            $this->item($action->fields, $room, $record, $ids, $text, $skip);
        }
    }

    /**
     * @param list<int> $ids
     */
    private function item(NightFields $fields, RoomType $room, ChangeRecord $record, array $ids, string $text, Closure $skip): void
    {
        $floor = $this->amount($fields, ChangeField::FLOOR_PCT, ChangeField::FLOOR_FIXED, $room, $record);
        $ceiling = $this->amount($fields, ChangeField::CEILING_PCT, ChangeField::CEILING_FIXED, $room, $record);
        $stay = $fields->get(ChangeField::MIN_STAY);
        $in = $fields->get(ChangeField::CHECK_IN_MASK);
        $out = $fields->get(ChangeField::CHECK_OUT_MASK);
        $lost = array_values(array_intersect(array_keys($fields->all()), [ChangeField::PRICE, ChangeField::PRICE_TYPE, ChangeField::EXPIRY]));
        if ($lost !== []) {
            $skip(UndoSkip::NOT_RESTORABLE, $lost);
        }
        if ($floor === null && $ceiling === null && $stay === null && $in === null && $out === null) {
            return;
        }
        $this->items[] = new PlanItem(
            $room->code,
            $record->night,
            $floor,
            $ceiling,
            $stay === null ? null : (int) $stay,
            self::REASON,
            ['undo_of' => $ids],
            null,
            $in === null ? null : (string) $in,
            $out === null ? null : (string) $out,
            reasonText: $text,
        );
    }

    /**
     * The amount of an old floor or ceiling: its percentage over today's base, or the amount a third
     * party had set (written as the nearest whole percentage, said in the notes).
     */
    private function amount(NightFields $fields, string $percentField, string $fixedField, RoomType $room, ChangeRecord $record): ?float
    {
        $percent = $fields->get($percentField);
        if (is_int($percent) || is_float($percent)) {
            return $room->base * (1 + $percent / 100);
        }
        $fixed = $fields->get($fixedField);
        if (!is_int($fixed) && !is_float($fixed)) {
            return null;
        }
        $this->notes[] = sprintf('%s %s: the amount %.2f is restored as the nearest whole percentage of the base.', $room->code, $record->night?->format('Y-m-d') ?? '', (float) $fixed);

        return (float) $fixed;
    }
}
