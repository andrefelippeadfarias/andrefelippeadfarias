<?php

declare(strict_types=1);

namespace Ritmo\Application\Dashboard\Overview;

use Ritmo\Domain\Model\BookingRecord;
use Ritmo\Domain\Model\RoomType;
use Ritmo\Domain\Support\DateRange;
use Ritmo\Domain\Support\Num;

/**
 * Revenue on the books of a window: each stored booking's total spread evenly over its nights
 * (and units), counting the nights inside the window. The average daily rate is that revenue
 * divided by the room nights sold. Until the report of spec 06 (NightRevenue) exists, this is
 * the overview's source; bookings without a total add room nights but no revenue.
 */
final class Revenue
{
    private function __construct()
    {
    }

    /**
     * @param list<BookingRecord> $bookings
     * @param list<RoomType>      $rooms    the property's rooms (bookings of other rooms are left out)
     *
     * @return array{revenue: float, bookings: int, room_nights: int}
     */
    public static function onTheBooks(array $bookings, array $rooms, DateRange $window): array
    {
        $codes = array_flip(array_map(static fn (RoomType $r): string => $r->code, $rooms));
        $revenue = 0.0;
        $count = 0;
        $roomNights = 0;
        foreach ($bookings as $booking) {
            if ($booking->roomCode === null || !isset($codes[$booking->roomCode]) || !$booking->status->occupies()) {
                continue;
            }
            $stay = $booking->nights();
            $inside = $stay->intersect($window);
            if ($inside === null) {
                continue;
            }
            $units = max(1, $booking->units);
            ++$count;
            $roomNights += $inside->count() * $units;
            if ($booking->total !== null && $stay->count() > 0) {
                $revenue += $booking->total / $stay->count() * $inside->count();
            }
        }

        return ['revenue' => Num::roundHalfEven($revenue, 2), 'bookings' => $count, 'room_nights' => $roomNights];
    }
}
