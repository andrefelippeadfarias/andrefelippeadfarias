<?php

declare(strict_types=1);

namespace Ritmo\Domain\Pricing;

use InvalidArgumentException;

/**
 * What the rest of the plan changes in the pace step of one room night: an owner order, the sales
 * ramp, a strategy's market floor, the bounds of a room relation, the chained listing top. The
 * plan assembly computes it ({@see Terms\NightTermsResolver}); the neutral terms change nothing,
 * so a night without them is decided exactly as in milestone 1.
 *
 * Reference: the pace branch of pace._plano (lines 908-1081 of the reference engine).
 */
final class PaceTerms
{
    /**
     * @param float|null     $percentile     target percentile that replaces the one of the pace status
     *                                       (an owner target order, already ramped by its sales)
     * @param bool           $byOrder        the night follows an owner order: "ahead never lowers" does not apply
     * @param float|null     $rampTop        the sales ramp: the status percentile climbs toward this top ...
     * @param float          $rampShare      ... in proportion to the share sold (0 to 1)
     * @param bool           $freeStep       a target under the price is reached at once, without the step limit
     * @param OrderSalesRamp|null $ramp     the sales ramp of the night's target order: it decides the price and
     *                                       may lower the floor, instead of the step
     * @param HardFloor|null $floor          the floor that replaces the hard floor of the night
     * @param float|null     $ceilingCap     a relation's ceiling: the price stays under it (never under the floor)
     * @param bool           $onlyDown       the order only lowers: never above the current price
     * @param float|null     $listingTop     the highest floor the listing (or its chain of relations) allows
     * @param bool           $compareApplied a rise is measured on the floor as the pricing system applies it
     * @param bool           $lockCeilingDown   the ceiling goes with the floor on a step down (an order's free step)
     * @param bool           $lockCeilingAlways the ceiling goes with the floor on every step (an order's ramp)
     */
    public function __construct(
        public readonly ?float $percentile = null,
        public readonly bool $byOrder = false,
        public readonly ?float $rampTop = null,
        public readonly float $rampShare = 0.0,
        public readonly bool $freeStep = false,
        public readonly ?OrderSalesRamp $ramp = null,
        public readonly ?HardFloor $floor = null,
        public readonly ?float $ceilingCap = null,
        public readonly bool $onlyDown = false,
        public readonly ?float $listingTop = null,
        public readonly bool $compareApplied = false,
        public readonly bool $lockCeilingDown = false,
        public readonly bool $lockCeilingAlways = false,
    ) {
        if ($rampShare < 0.0 || $rampShare > 1.0) {
            throw new InvalidArgumentException('The share sold of the sales ramp must be between 0 and 1.');
        }
        foreach ([$percentile, $rampTop] as $value) {
            if ($value !== null && ($value < 0.0 || $value > 100.0)) {
                throw new InvalidArgumentException('Percentiles are between 0 and 100.');
            }
        }
    }

    /**
     * The percentile of the night: the order's, else the status percentile on the sales ramp
     * (pace.percentil_com_vendas: it climbs toward the top in proportion to the share sold and
     * never goes down).
     */
    public function percentileFor(float $statusPercentile): float
    {
        if ($this->percentile !== null) {
            return $this->percentile;
        }
        if ($this->rampTop !== null && $this->rampTop > $statusPercentile) {
            return $statusPercentile + ($this->rampTop - $statusPercentile) * $this->rampShare;
        }

        return $statusPercentile;
    }
}
