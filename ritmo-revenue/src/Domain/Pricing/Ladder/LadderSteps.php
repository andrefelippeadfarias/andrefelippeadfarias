<?php

declare(strict_types=1);

namespace Ritmo\Domain\Pricing\Ladder;

/**
 * The steps of a ladder: after a fraction of the units sells at the opening price, every further
 * unit adds a compound percentage.
 */
final class LadderSteps
{
    public function __construct(
        public readonly float $triggerFraction = 0.5,
        public readonly float $percentPerUnit = 5.0,
    ) {
    }
}
