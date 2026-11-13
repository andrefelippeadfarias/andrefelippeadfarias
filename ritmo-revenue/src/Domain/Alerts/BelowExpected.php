<?php

declare(strict_types=1);

namespace Ritmo\Domain\Alerts;

use Ritmo\Domain\Model\BookingRecord;
use Ritmo\Domain\Support\Num;

/**
 * Bookings worth less than expected (operations spec 5.3).
 *
 * For each confirmed or new booking: expected = (sum of the PMS price of each night in its room) x
 * the factor of its channel; ratio = total / expected; a finding when the ratio (unrounded) is
 * under the threshold, critical under the critical threshold. A booking without total, or with a
 * night without price (or a price of 0), is skipped. Sorted by the ratio as shown (3 decimals).
 *
 * Reference: reservas_abaixo.avaliar. The reference maps every channel but its two OTAs to
 * "direct"; here a channel without its own factor takes the default factor. The guest name and the
 * rate description the reference shows are never read (O25).
 */
final class BelowExpected
{
    public function __construct(private readonly BelowExpectedConfig $config)
    {
    }

    /**
     * @param list<BookingRecord>                      $bookings
     * @param array<string, array<string, float|null>> $prices   room code => 'YYYY-MM-DD' => PMS price
     *
     * @return list<BelowExpectedFinding>
     */
    public function evaluate(array $bookings, array $prices, string $propertyId): array
    {
        $findings = [];
        foreach ($bookings as $booking) {
            $finding = $this->one($booking, $prices, $propertyId);
            if ($finding !== null) {
                $findings[] = $finding;
            }
        }
        usort($findings, static fn (BelowExpectedFinding $a, BelowExpectedFinding $b): int => $a->ratio <=> $b->ratio);

        return $findings;
    }

    /**
     * @param array<string, array<string, float|null>> $prices
     */
    private function one(BookingRecord $booking, array $prices, string $propertyId): ?BelowExpectedFinding
    {
        if (!$booking->status->occupies() || $booking->roomCode === null || $booking->total === null || $booking->total == 0.0) {
            return null;
        }
        $sum = 0.0;
        $nights = 0;
        foreach ($booking->nights()->keys() as $night) {
            $price = $prices[$booking->roomCode][$night] ?? null;
            if ($price === null || $price == 0.0) {
                return null;
            }
            $sum += $price;
            ++$nights;
        }
        $expected = $sum * $this->config->factor($propertyId, $booking->channel);
        $ratio = $booking->total / $expected;
        if ($ratio >= $this->config->threshold) {
            return null;
        }

        return new BelowExpectedFinding(
            $booking,
            $nights,
            Num::roundHalfEven($booking->total, 2),
            Num::roundHalfEven($expected, 2),
            Num::roundHalfEven($ratio, 3),
            $ratio < $this->config->criticalThreshold ? AlertSeverity::Critical : AlertSeverity::Warning,
        );
    }
}
