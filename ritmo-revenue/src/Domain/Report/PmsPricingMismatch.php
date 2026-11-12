<?php

declare(strict_types=1);

namespace Ritmo\Domain\Report;

use Ritmo\Domain\Model\BookingRecord;
use Ritmo\Domain\Model\PriceNight;
use Ritmo\Domain\Support\DateRange;
use Ritmo\Domain\Support\Days;

/**
 * Compares the units sold according to the bookings of the PMS with the units the pricing system
 * counts as sold, night by night.
 *
 * Reference: beds24.comparar.
 */
final class PmsPricingMismatch
{
    /**
     * @param list<BookingRecord>             $bookings bookings of the PMS, of any status
     * @param array<string, list<PriceNight>> $pricing  room code => nights of the pricing system
     * @param DateRange                       $range    nights to compare
     *
     * @return list<SoldMismatch> the nights of $pricing inside the range where the two differ, in the order given
     */
    public function find(array $bookings, array $pricing, DateRange $range): array
    {
        $pmsSold = BookedUnits::byRoomNight($bookings, $range);
        $mismatches = [];
        foreach ($pricing as $roomCode => $nights) {
            foreach ($nights as $night) {
                if (!$range->contains($night->date)) {
                    continue;
                }
                $inPms = $pmsSold[$roomCode][Days::key($night->date)] ?? 0;
                if ($night->sold !== $inPms) {
                    $mismatches[] = new SoldMismatch((string) $roomCode, $night->date, $inPms, $night->sold);
                }
            }
        }

        return $mismatches;
    }
}
