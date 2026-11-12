<?php

declare(strict_types=1);

namespace Ritmo\Connectors\Beds24;

use Ritmo\Connectors\Support\HttpFailure;
use Ritmo\Connectors\Support\Payload;
use Ritmo\Contracts\Exception\ConnectorException;
use Ritmo\Domain\Model\InventoryNight;
use Ritmo\Domain\Model\Restriction;
use Ritmo\Domain\Support\DateRange;

/**
 * Turns the calendar of one room, which Beds24 sends as ranges of equal days, into nights.
 */
final class InventoryMapper
{
    private const OPERATION = 'GET /inventory/rooms/calendar';

    /** The "override" of a calendar day, compared in lowercase. */
    private const RESTRICTIONS = [
        '' => Restriction::None,
        'none' => Restriction::None,
        'blackout' => Restriction::Blackout,
        'nocheckin' => Restriction::NoCheckin,
        'nocheckout' => Restriction::NoCheckout,
        'nocheckinorcheckout' => Restriction::NoCheckinCheckout,
    ];

    /**
     * The nights of a room inside the range, in ascending order. A day without a number of
     * available units is not reported; a later range replaces an earlier one for the same day.
     *
     * @param list<array<int|string, mixed>> $calendar the "calendar" ranges of one room
     *
     * @return list<InventoryNight>
     *
     * @throws ConnectorException when a range has no valid dates or an unknown restriction
     */
    public function nights(array $calendar, DateRange $range): array
    {
        $nights = [];
        foreach ($calendar as $span) {
            $from = Payload::date($span, 'from');
            $to = Payload::date($span, 'to') ?? $from;
            if ($from === null || $to === null || $to < $from) {
                throw HttpFailure::unexpectedShape(Beds24Connector::ID, 'Beds24', self::OPERATION, 'a calendar range has no valid dates');
            }

            $available = Payload::integer($span, 'numAvail');
            $inside = (new DateRange($from, $to))->intersect($range);
            if ($available === null || $inside === null) {
                continue;
            }

            $restriction = self::restriction($span);
            $minStay = Payload::count($span, 'minStay');
            $maxStay = Payload::count($span, 'maxStay');
            $price = Payload::number($span, 'price1');
            foreach ($inside as $key => $night) {
                $nights[$key] = new InventoryNight($night, $available, $minStay, $maxStay, $restriction, $price !== null && $price >= 0 ? $price : null);
            }
        }

        ksort($nights);

        return array_values($nights);
    }

    /**
     * @param array<int|string, mixed> $span
     *
     * @throws ConnectorException
     */
    private static function restriction(array $span): Restriction
    {
        $restriction = self::RESTRICTIONS[strtolower(Payload::text($span, 'override', 32))] ?? null;
        if ($restriction === null) {
            throw HttpFailure::unexpectedShape(Beds24Connector::ID, 'Beds24', self::OPERATION, 'a calendar day has an unknown restriction');
        }

        return $restriction;
    }
}
