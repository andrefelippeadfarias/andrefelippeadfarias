<?php

declare(strict_types=1);

namespace Ritmo\Application\Grid\Approvals;

use DateTimeImmutable;
use InvalidArgumentException;
use Ritmo\Application\Approval\PlanEntry;
use Ritmo\Application\Grid\PlanIndex;
use Ritmo\Domain\Calendar\HolidayCalendar;
use Ritmo\Domain\Model\RoomType;
use Ritmo\Domain\Support\Days;
use Ritmo\Domain\Support\Num;
use Ritmo\Domain\Write\Hold\WaveHold;

/**
 * Turns the stored entries of a request into EntryRows: amounts before and after from the plan
 * items of the run that proposed it (or, without them, the amount the sent percentage stands for),
 * the direction, the reason and the warnings.
 */
final class RowBuilder
{
    /**
     * @param array<string, RoomType> $rooms code => room type (base and listing values)
     */
    public function __construct(
        private readonly PlanIndex $plan,
        private readonly array $rooms,
        private readonly HolidayCalendar $calendar,
        private readonly DateTimeImmutable $today,
        private readonly ?WaveHold $hold,
    ) {
    }

    /**
     * @param list<array<string, mixed>> $stored entries as plan_approvals keeps them
     * @param list<string>               $linkedKeys keys of entries tied to another room by a relation
     *
     * @return list<EntryRow> in stored order; malformed entries are left out
     */
    public function rows(array $stored, array $linkedKeys = []): array
    {
        $entries = [];
        foreach ($stored as $data) {
            try {
                $entries[] = PlanEntry::fromArray(is_array($data) ? $data : []);
            } catch (InvalidArgumentException) {
                continue;
            }
        }
        $groupSize = array_count_values(array_map(static fn (PlanEntry $e): string => $e->group(), $entries));
        $linked = array_flip($linkedKeys);

        return array_map(fn (PlanEntry $e): EntryRow => $e->kind === PlanEntry::LISTING
            ? $this->listing($e)
            : $this->night($e, ($groupSize[$e->group()] ?? 1) > 1, isset($linked[$e->key()])), $entries);
    }

    private function night(PlanEntry $entry, bool $inGroup, bool $linked): EntryRow
    {
        $night = Days::isValid($entry->subject) ? Days::date($entry->subject) : null;
        $key = $night === null ? '' : Days::key($night);
        $planned = $this->plan->night($entry->roomCode, $key);
        $base = $this->rooms[$entry->roomCode]->base ?? 0.0;
        $floorPct = self::int($entry->fields['floor_pct'] ?? null);
        $ceilingPct = self::int($entry->fields['ceiling_pct'] ?? null);
        $floorAfter = $floorPct === null ? null : ($planned->floorProposed ?? self::amount($floorPct, $base));
        $ceilingAfter = $ceilingPct === null ? null : ($planned->ceilingProposed ?? self::amount($ceilingPct, $base));
        $holiday = $night === null ? '' : ($this->calendar->holidayOn($night)?->name ?? '');
        $held = $night !== null && $this->hold !== null && $this->hold->coversNight($entry->roomCode, $night)
            && $floorAfter !== null && $planned->floorNow !== null && $floorAfter < $planned->floorNow;

        return new EntryRow(
            $entry->key(),
            $entry->kind,
            $entry->roomCode,
            $night,
            $entry->subject,
            $entry->group(),
            $entry->fields,
            $entry->orderId,
            self::direction($entry, $inGroup, $planned->floorNow, $floorAfter),
            $planned->floorNow,
            $floorAfter,
            $floorPct,
            $planned->ceilingNow,
            $ceilingAfter,
            $ceilingPct,
            $entry->kind === PlanEntry::DELETION ? 'CEILING_RELEASE_DELETE' : $planned->reason,
            $planned->paceStatus,
            $this->plan->trace($entry->roomCode, $key),
            EntryWarnings::special($night, $this->today, $holiday),
            EntryWarnings::of($night, $this->today, $holiday, $inGroup, $held, $entry->orderId, $linked),
            $held,
            $planned->nowFromListing,
        );
    }

    private function listing(PlanEntry $entry): EntryRow
    {
        $room = $this->rooms[$entry->roomCode] ?? null;
        $before = match ($entry->subject) {
            'min' => $room?->min,
            'base' => $room?->base,
            'max' => $room?->max,
            default => null,
        };
        $after = is_numeric($entry->fields['value'] ?? null) ? (float) $entry->fields['value'] : null;

        return new EntryRow($entry->key(), $entry->kind, $entry->roomCode, null, $entry->subject, $entry->group(), $entry->fields, $entry->orderId, EntryRow::LISTING, $before, $after, null, null, null, null, '', null, [], '', $entry->orderId === null ? [] : [EntryWarnings::ORDER], false);
    }

    private static function direction(PlanEntry $entry, bool $inGroup, ?float $before, ?float $after): string
    {
        if ($entry->kind === PlanEntry::DELETION || $inGroup) {
            return EntryRow::REWRITE;
        }
        if ($after === null || $before === null || abs($after - $before) < 0.005) {
            return EntryRow::BOUNDS;
        }

        return $after > $before ? EntryRow::UP : EntryRow::DOWN;
    }

    private static function amount(int $percent, float $base): ?float
    {
        return $base > 0 ? Num::percentToAmount($percent, $base) : null;
    }

    private static function int(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }
}
