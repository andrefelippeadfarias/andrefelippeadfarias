<?php

declare(strict_types=1);

namespace Ritmo\Domain\Report\Weekly;

use DateTimeImmutable;

/**
 * The stays of one week (Monday to Sunday) of one property: room nights over the week's
 * capacity, revenue, average daily rate and revenue per available room night.
 */
final class StayWeek
{
    public function __construct(
        public readonly DateTimeImmutable $monday,
        public readonly string $propertyId,
        public readonly Moment $moment,
        public readonly int $roomNights,
        public readonly int $capacity,
        public readonly ?float $occupancy,
        public readonly float $revenue,
        public readonly ?float $adr,
        public readonly ?float $revpar,
    ) {
    }
}
