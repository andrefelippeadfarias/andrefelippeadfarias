<?php

declare(strict_types=1);

namespace Ritmo\Domain\Report\Weekly;

use DateTimeImmutable;
use Ritmo\Domain\Report\Booking\BookingTotals;

/**
 * Bookings made in one week (Monday to Sunday at the property) for one property, with the
 * cancellations made that week.
 */
final class MadeWeek
{
    public function __construct(
        public readonly DateTimeImmutable $monday,
        public readonly string $propertyId,
        public readonly bool $complete,
        public readonly BookingTotals $totals,
        public readonly int $cancellations,
        public readonly float $cancelledRevenue,
    ) {
    }
}
