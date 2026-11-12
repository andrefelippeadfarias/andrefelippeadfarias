<?php

declare(strict_types=1);

namespace Ritmo\Domain\Plan;

use Ritmo\Domain\Support\Num;

/**
 * A plan item as the pricing system takes it: floor and ceiling as whole percentages over the
 * base of the listing (-31 means base x 0.69).
 */
final class PercentItem
{
    /**
     * @param float    $base       base price of the listing the percentages are over
     * @param int|null $minPercent floor, rounded up; null when the item sets no floor
     * @param int|null $maxPercent ceiling, rounded down and never under the floor; null when the item sets no ceiling
     */
    public function __construct(
        public readonly PlanItem $item,
        public readonly float $base,
        public readonly ?int $minPercent,
        public readonly ?int $maxPercent,
    ) {
    }

    /**
     * The floor the pricing system will apply: the base plus the percentage. With no decimals it
     * is the whole amount the system computes; with two, the exact amount of the percentage.
     */
    public function minAmount(int $decimals = 0): ?float
    {
        return $this->minPercent === null ? null : Num::percentToAmount((float) $this->minPercent, $this->base, $decimals);
    }

    /**
     * The ceiling the pricing system will apply.
     */
    public function maxAmount(int $decimals = 0): ?float
    {
        return $this->maxPercent === null ? null : Num::percentToAmount((float) $this->maxPercent, $this->base, $decimals);
    }

    /**
     * True when everything the item sets is already in force as the same percentage (and the
     * same minimum stay): writing it would change nothing, the amounts differ only by rounding.
     * False whenever what is in force is not known.
     */
    public function sameAsInForce(): bool
    {
        $before = $this->item->before;
        if ($before === null) {
            return false;
        }

        return ($this->minPercent === null || $before->minPercent === (float) $this->minPercent)
            && ($this->maxPercent === null || $before->maxPercent === (float) $this->maxPercent)
            && ($this->item->minStay === null || $before->minStay === $this->item->minStay);
    }
}
