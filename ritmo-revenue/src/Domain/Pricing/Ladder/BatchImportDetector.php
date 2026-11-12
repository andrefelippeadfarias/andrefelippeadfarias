<?php

declare(strict_types=1);

namespace Ritmo\Domain\Pricing\Ladder;

use DateTimeImmutable;
use DateTimeZone;
use Ritmo\Domain\Model\BookingRecord;
use Ritmo\Domain\Support\Days;

/**
 * Finds room nights sold by a batch import of the channel manager: bookings created in the same
 * minute, from a minimum number on, are an import and not demand, so the proportional ladder does
 * not count them as new sales.
 *
 * Reference: escada_reservas.lotes (bookings.batch_import.min_same_minute = 3).
 */
final class BatchImportDetector
{
    public function __construct(private readonly int $minSameMinute = 3)
    {
    }

    /**
     * Units of each room night sold by batch imports created at or after $since: one per booking
     * and night. Bookings without a creation instant, unmapped or not occupying are ignored.
     *
     * @param list<BookingRecord> $bookings
     *
     * @return array<string, int> keyed "ROOM|YYYY-MM-DD"
     */
    public function batchNights(array $bookings, ?DateTimeImmutable $since = null): array
    {
        $byMinute = [];
        foreach ($bookings as $booking) {
            if ($booking->createdAt === null || $booking->roomCode === null || !$booking->status->occupies()) {
                continue;
            }
            if ($since !== null && $booking->createdAt < $since) {
                continue;
            }
            $byMinute[$booking->createdAt->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i')][] = $booking;
        }
        $nights = [];
        foreach ($byMinute as $group) {
            if (count($group) < $this->minSameMinute) {
                continue;
            }
            foreach ($group as $booking) {
                foreach ($booking->nights() as $night) {
                    $key = $booking->roomCode . '|' . Days::key($night);
                    $nights[$key] = ($nights[$key] ?? 0) + 1;
                }
            }
        }

        return $nights;
    }
}
