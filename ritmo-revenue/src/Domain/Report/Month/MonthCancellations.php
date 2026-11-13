<?php

declare(strict_types=1);

namespace Ritmo\Domain\Report\Month;

/**
 * Cancellations made in a month (by the cancellation instant), of bookings made at any time.
 */
final class MonthCancellations
{
    public function __construct(
        public readonly ?string $propertyId,
        public readonly int $count,
        public readonly float $revenue,
        public readonly int $ofBookingsMadeBefore,
    ) {
    }
}
