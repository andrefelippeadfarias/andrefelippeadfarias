<?php

declare(strict_types=1);

namespace Ritmo\Domain\Pricing;

use Ritmo\Domain\Model\PaceStatus;

/**
 * How a night is selling against its expected curve.
 */
final class PaceReading
{
    /**
     * @param float $soldPercent     share of the units sold, 0 to 100 (above 100 when overbooked)
     * @param float $expectedPercent share the curve expects at this distance from the night
     */
    public function __construct(
        public readonly PaceStatus $status,
        public readonly float $soldPercent,
        public readonly float $expectedPercent,
    ) {
    }

    /**
     * Points sold above (positive) or below (negative) the curve.
     */
    public function gap(): float
    {
        return $this->soldPercent - $this->expectedPercent;
    }
}
