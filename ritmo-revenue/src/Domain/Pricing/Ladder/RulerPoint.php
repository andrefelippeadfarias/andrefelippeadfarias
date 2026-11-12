<?php

declare(strict_types=1);

namespace Ritmo\Domain\Pricing\Ladder;

/**
 * A point of the market ruler: a price series at a stored percentile, times a multiplier.
 */
final class RulerPoint
{
    public function __construct(
        public readonly string $series,
        public readonly float $percentile,
        public readonly float $multiplier = 1.0,
    ) {
    }
}
