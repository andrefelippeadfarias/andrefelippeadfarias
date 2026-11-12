<?php

declare(strict_types=1);

namespace Ritmo\Domain\Report;

use Ritmo\Domain\Model\BookingRecord;
use Ritmo\Domain\Support\DateRange;

/**
 * Counts the units sold per room type and night from the bookings of the PMS.
 *
 * Only bookings that occupy a room count (confirmed and new); each takes its units on every
 * night from the arrival to the day before the departure. Bookings of rooms that are not mapped
 * to a room type are left out.
 *
 * Reference: beds24.noites.
 */
final class BookedUnits
{
    /**
     * @param list<BookingRecord> $bookings
     *
     * @return array<string, array<string, int>> room code => 'YYYY-MM-DD' => units sold; nights with nothing sold are absent
     */
    public static function byRoomNight(array $bookings, DateRange $range): array
    {
        $sold = [];
        foreach ($bookings as $booking) {
            if ($booking->roomCode === null || !$booking->status->occupies()) {
                continue;
            }
            $nights = $booking->nights()->intersect($range);
            if ($nights === null) {
                continue;
            }
            foreach ($nights->keys() as $night) {
                $sold[$booking->roomCode][$night] = ($sold[$booking->roomCode][$night] ?? 0) + $booking->units;
            }
        }

        return $sold;
    }
}
