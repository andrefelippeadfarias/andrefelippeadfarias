<?php

declare(strict_types=1);

namespace Ritmo\Domain\Report\Review;

use DateTimeImmutable;

/**
 * The 12-month review: the rows of the current month and the next 12 per property, and the day
 * of the snapshot it was compared with.
 */
final class ReviewResult
{
    /**
     * @param list<ReviewRow> $rows
     */
    public function __construct(
        public readonly DateTimeImmutable $today,
        public readonly DateTimeImmutable $asOf,
        public readonly ?DateTimeImmutable $previousDay,
        public readonly array $rows,
    ) {
    }

    /**
     * @return list<ReviewRow> the months the property sells behind the market
     */
    public function behind(): array
    {
        return array_values(array_filter($this->rows, static fn (ReviewRow $r): bool => $r->reading === Reading::Behind));
    }
}
