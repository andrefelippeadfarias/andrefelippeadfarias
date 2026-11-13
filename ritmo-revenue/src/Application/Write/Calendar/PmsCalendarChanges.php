<?php

declare(strict_types=1);

namespace Ritmo\Application\Write\Calendar;

use InvalidArgumentException;
use Ritmo\Application\Write\Schedule\CalendarChange;
use Ritmo\Domain\Model\Restriction;
use Ritmo\Domain\Model\RoomType;
use Ritmo\Domain\Stay\Write\PmsChangeEntry;
use Ritmo\Domain\Write\State\ChangeField;

/**
 * The PMS changes the check accepted, as the calendar changes of the write path (M3), in their
 * order (the single batch's: urgent nights first). One night each; the minimum stay read when
 * planned rides along (a night changed since is skipped by the guard). alsoPricing is set only
 * when a pricing step of this run must go first: a change the pricing calendar already holds
 * (pricingHolds) goes on its own (SW-X2). Never a price, never a booking.
 */
final class PmsCalendarChanges
{
    private function __construct()
    {
    }

    /**
     * @param list<PmsChangeEntry>    $accepted
     * @param array<string, RoomType> $rooms room code => room
     *
     * @return list<CalendarChange>
     *
     * @throws InvalidArgumentException on a change of an unknown room
     */
    public static function of(array $accepted, array $rooms): array
    {
        $out = [];
        foreach ($accepted as $entry) {
            $room = $rooms[$entry->roomCode] ?? throw new InvalidArgumentException(sprintf('Unknown room "%s" in the PMS changes.', $entry->roomCode));
            $before = $entry->before->get(ChangeField::PMS_MIN_STAY);
            $available = $entry->target->get(ChangeField::PMS_AVAILABLE);
            $out[] = new CalendarChange(
                $room->code,
                $room->pmsRef,
                $entry->night,
                $entry->night,
                minStay: $entry->minStay(),
                maxStay: $entry->maxStay(),
                restriction: $entry->restriction(),
                available: is_int($available) ? $available : null,
                channels: self::channels($entry),
                minStayBefore: $entry->minStay() !== null && is_int($before) ? $before : null,
                alsoPricing: $entry->needsPricingStep(),
                reason: $entry->reason,
                read: self::read($entry),
            );
        }

        return $out;
    }

    /**
     * The stay values read before the plan, for each stay field the change sets and the read
     * holds (a value not read is not guarded): the minimum stay goes as $minStayBefore.
     *
     * @return array<string, int|string|null> API field => value read
     */
    private static function read(PmsChangeEntry $entry): array
    {
        $read = [];
        $max = $entry->before->get(ChangeField::PMS_MAX_STAY);
        if ($entry->maxStay() !== null && is_int($max)) {
            $read['maxStay'] = $max;
        }
        $restriction = Restriction::tryFrom((string) $entry->before->get(ChangeField::PMS_RESTRICTION));
        if ($entry->restriction() !== null && $restriction !== null) {
            $read['override'] = CalendarChange::apiRestriction($restriction);
        }

        return $read;
    }

    /**
     * @return array<string, int|null> channel => booking limit (null lifts it)
     */
    private static function channels(PmsChangeEntry $entry): array
    {
        $out = [];
        foreach ($entry->target->all() as $field => $value) {
            if (str_starts_with($field, ChangeField::PMS_CHANNEL_LIMIT)) {
                $out[substr($field, strlen(ChangeField::PMS_CHANNEL_LIMIT))] = is_numeric($value) ? (int) $value : null;
            }
        }

        return $out;
    }
}
