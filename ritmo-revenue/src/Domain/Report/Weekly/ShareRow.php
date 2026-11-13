<?php

declare(strict_types=1);

namespace Ritmo\Domain\Report\Weekly;

use Ritmo\Domain\Report\Booking\BookingTotals;

/**
 * Bookings of the last 28 days of one property for one channel or room, with their share of the
 * property's active revenue of those days.
 */
final class ShareRow
{
    public function __construct(
        public readonly string $propertyId,
        public readonly string $key,
        public readonly BookingTotals $totals,
        public readonly float $share,
    ) {
    }
}
