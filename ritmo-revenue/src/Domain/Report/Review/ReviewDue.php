<?php

declare(strict_types=1);

namespace Ritmo\Domain\Report\Review;

use DateTimeImmutable;
use Ritmo\Domain\Support\Days;

/**
 * Whether a new review is due: never taken, or taken $intervalDays or more days ago.
 *
 * Reference: revisao_12m.devida.
 */
final class ReviewDue
{
    private function __construct(public readonly bool $due, public readonly ?int $daysSinceLast)
    {
    }

    public static function of(DateTimeImmutable $today, ?DateTimeImmutable $lastTaken, int $intervalDays): self
    {
        if ($lastTaken === null) {
            return new self(true, null);
        }
        $days = Days::diff($lastTaken, $today);

        return new self($days >= $intervalDays, $days);
    }
}
