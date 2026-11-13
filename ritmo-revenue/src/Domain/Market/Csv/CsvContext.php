<?php

declare(strict_types=1);

namespace Ritmo\Domain\Market\Csv;

use DateTimeImmutable;
use Ritmo\Domain\Support\Days;

/**
 * What a market price file is read against: the property (its today, timezone and currency),
 * the competitors it has, the instant of the import and the limits of the market section.
 */
final class CsvContext
{
    public readonly DateTimeImmutable $today;

    public function __construct(
        public readonly string $propertyId,
        DateTimeImmutable $today,
        public readonly DateTimeImmutable $now,
        public readonly string $timezone,
        public readonly string $currency,
        public readonly CompetitorIndex $competitors,
        public readonly int $maxRows = 20000,
        public readonly int $maxStayNights = 7,
        public readonly int $maxSampleAgeDays = 30,
    ) {
        $this->today = Days::normalize($today);
    }
}
