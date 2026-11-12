<?php

declare(strict_types=1);

namespace Ritmo\Domain\Pricing;

/**
 * Where the sales ramp of an owner order puts one room night: its floor, the price before the
 * floor and the listing top are applied, and the line of the ramp it aimed at.
 */
final class RampStep
{
    public function __construct(
        public readonly HardFloor $floor,
        public readonly float $price,
        public readonly float $line,
    ) {
    }
}
