<?php

declare(strict_types=1);

namespace Ritmo\Domain\Market\Series;

use DateTimeImmutable;
use Ritmo\Domain\Support\Days;

/**
 * One series measured on one stay: its night type, how many competitor values it has, the
 * stored percentiles (whole amounts) and the sorted values (2 decimals, for "where does a price
 * sit"), with the oldest and newest capture of the prices used.
 */
final class StaySeries
{
    /**
     * @param array<string, int> $percentiles percentile ("25") => whole public price
     * @param list<float>        $values      sorted ascending, rounded to cents
     */
    public function __construct(
        public readonly string $series,
        public readonly DateTimeImmutable $checkIn,
        public readonly int $nights,
        public readonly string $type,
        public readonly int $n,
        public readonly array $percentiles,
        public readonly array $values,
        public readonly DateTimeImmutable $oldestCapture,
        public readonly DateTimeImmutable $newestCapture,
    ) {
    }

    public function checkOut(): DateTimeImmutable
    {
        return Days::add($this->checkIn, $this->nights);
    }

    public function contains(DateTimeImmutable $night): bool
    {
        return $this->checkIn <= $night && $night < $this->checkOut();
    }

    /**
     * Days between the night and the stay: to its check-in or to its last night, the smaller.
     */
    public function distanceTo(DateTimeImmutable $night): int
    {
        return min(abs(Days::diff($night, $this->checkIn)), abs(Days::diff($night, Days::add($this->checkOut(), -1))));
    }
}
