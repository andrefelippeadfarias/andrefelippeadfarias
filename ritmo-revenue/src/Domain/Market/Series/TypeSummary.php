<?php

declare(strict_types=1);

namespace Ritmo\Domain\Market\Series;

use DateTimeImmutable;

/**
 * One series on one kind of night, over all its measured stays: the typical value of each
 * percentile (the median of the monthly medians), how many stays and competitors it rests on,
 * and whether that is a small sample.
 */
final class TypeSummary
{
    /**
     * @param list<string>       $months check-in months ("YYYY-MM"), sorted
     * @param array<string, int> $values percentile ("25") => whole public price
     */
    public function __construct(
        public readonly string $type,
        public readonly int $stays,
        public readonly array $months,
        public readonly int $competitors,
        public readonly DateTimeImmutable $from,
        public readonly DateTimeImmutable $to,
        public readonly bool $smallSample,
        public readonly array $values,
    ) {
    }
}
