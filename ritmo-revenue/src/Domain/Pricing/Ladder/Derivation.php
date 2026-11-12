<?php

declare(strict_types=1);

namespace Ritmo\Domain\Pricing\Ladder;

/**
 * A room priced from another room of the same night: that room's price times a multiplier.
 */
final class Derivation
{
    public function __construct(
        public readonly string $fromRoom,
        public readonly float $multiplier,
    ) {
    }
}
