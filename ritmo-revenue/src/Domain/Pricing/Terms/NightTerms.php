<?php

declare(strict_types=1);

namespace Ritmo\Domain\Pricing\Terms;

use Ritmo\Domain\Pricing\HardFloor;
use Ritmo\Domain\Pricing\OrderSalesRamp;
use Ritmo\Domain\Pricing\PaceTerms;

/**
 * The terms of the pace step of one room night and the owner order its item cites.
 *
 * Which order an item cites depends on how far the step went: when a strategy's market floor
 * lowered the floor but the step stayed at or above the floor it replaced, the item cites the
 * order of that floor (or none). A relation that set the floor or capped the price cites its own
 * order. A sales ramp that lowered the floor (fallback floor or descent step) cites its target
 * order when no other order set the floor. Reference: pace._plano (po, sem_r3, ordem_piso or ao,
 * regra_af).
 */
final class NightTerms
{
    /**
     * @param string|null $floorOrder        the order whose floor the step is held at (a floor order, or the
     *                                       strategy order of a market floor)
     * @param float|null  $replacedFloor     the floor a market floor replaced, when it did
     * @param string|null $replacedOrder     the order of that floor, or null
     * @param string|null $relationOrder     the order of a relation that set the floor or capped the price
     * @param string|null $targetOrder       the target order the night follows, or null
     * @param string|null $premiumOrder      the order of a premium relation on the night: cited when its floor
     *                                       is the one the step ended on
     */
    public function __construct(
        public readonly PaceTerms $pace,
        public readonly ?string $floorOrder = null,
        public readonly ?float $replacedFloor = null,
        public readonly ?string $replacedOrder = null,
        public readonly ?string $relationOrder = null,
        public readonly ?string $targetOrder = null,
        public readonly ?string $premiumOrder = null,
    ) {
    }

    public static function neutral(): self
    {
        return new self(new PaceTerms());
    }

    /**
     * The order the item cites, given the price after the step and the floor it ended on.
     */
    public function citedOrder(?float $stepPrice, ?HardFloor $floor = null): ?string
    {
        if ($this->relationOrder !== null) {
            return $this->relationOrder;
        }
        if ($this->premiumOrder !== null && $floor?->label === NightTermsResolver::PREMIUM_FLOOR) {
            return $this->premiumOrder;
        }
        if ($this->replacedFloor !== null && $stepPrice !== null && $stepPrice >= $this->replacedFloor - 0.5) {
            return $this->replacedOrder;
        }
        if ($this->floorOrder === null && $floor !== null && self::rampLowered($floor)) {
            return $this->targetOrder;
        }

        return $this->floorOrder;
    }

    private static function rampLowered(HardFloor $floor): bool
    {
        foreach ($floor->candidates as $candidate) {
            if ($candidate['label'] === OrderSalesRamp::FALLBACK_FLOOR || $candidate['label'] === OrderSalesRamp::DESCENT_STEP) {
                return true;
            }
        }

        return false;
    }
}
