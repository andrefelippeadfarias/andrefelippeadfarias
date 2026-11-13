<?php

declare(strict_types=1);

namespace Ritmo\Domain\Report\Review;

/**
 * One month of one property in the 12-month review.
 */
final class ReviewRow
{
    public function __construct(
        public readonly string $month,
        public readonly string $propertyId,
        public readonly float $soldRevenue,
        public readonly ?float $soldOccupancy,
        public readonly ?float $adr,
        public readonly ?float $minimum,
        public readonly ?float $realistic,
        public readonly ?float $achieved,
        public readonly ?float $pickupSinceLast,
        public readonly ?float $marketSoldPercent,
        public readonly ?float $marketLastYearPercent,
        public readonly ?Reading $reading,
        public readonly ?float $averagePrice,
        public readonly ?float $priceChange,
    ) {
    }
}
