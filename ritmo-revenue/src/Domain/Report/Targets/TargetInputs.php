<?php

declare(strict_types=1);

namespace Ritmo\Domain\Report\Targets;

use InvalidArgumentException;

/**
 * What the owner typed for one property and one month (table revenue_targets): last year's market
 * occupancy (percent) and daily rate, the occupancy cap (a factor, 1 = none), the manual
 * adjustment of each scenario (money; divergence RP-X4), the minimum and the realistic target.
 */
final class TargetInputs
{
    /**
     * @param array<string, float> $adjust scenario value => amount added to the month's forecast
     *
     * @throws InvalidArgumentException on values outside their ranges
     */
    public function __construct(
        public readonly ?float $marketOccupancyLy = null,
        public readonly ?float $marketAdrLy = null,
        public readonly float $occupancyCap = 1.0,
        public readonly array $adjust = [],
        public readonly ?float $minimum = null,
        public readonly ?float $realistic = null,
    ) {
        if ($marketOccupancyLy !== null && ($marketOccupancyLy < 0 || $marketOccupancyLy > 100)) {
            throw new InvalidArgumentException('Last year\'s market occupancy is a percentage from 0 to 100.');
        }
        if (($marketAdrLy !== null && $marketAdrLy < 0) || $occupancyCap <= 0 || $occupancyCap > 1) {
            throw new InvalidArgumentException('The market daily rate cannot be negative and the occupancy cap is above 0 and at most 1.');
        }
        foreach ([...array_values($adjust), $minimum, $realistic] as $amount) {
            if ($amount !== null && !is_finite($amount)) {
                throw new InvalidArgumentException('Amounts must be finite numbers.');
            }
        }
    }

    public function hasMarket(): bool
    {
        return $this->marketOccupancyLy !== null && $this->marketAdrLy !== null;
    }

    public function adjust(Scenario $scenario): float
    {
        return $this->adjust[$scenario->value] ?? 0.0;
    }

    /**
     * last year's market occupancy / 100 x its daily rate x growth x index x units x days x cap,
     * multiplied in this order (the reference's order, so the rounding matches).
     */
    public function value(float $growth, float $index, int $units, int $days): float
    {
        return (float) $this->marketOccupancyLy / 100 * (float) $this->marketAdrLy * $growth * $index * $units * $days * $this->occupancyCap;
    }
}
