<?php

declare(strict_types=1);

namespace Ritmo\Domain\Relations\Port;

/**
 * What the floor rules and the sales ladder add to a between relation on a night.
 */
final class BetweenHints
{
    /** An order that lowered the floor is cited only when it moved the lower edge by more than this. */
    public const ORDER_LOWERING_MIN = 0.5;

    /**
     * @param float       $ownFloor           the target's floor tonight (the lower edge never goes below it)
     * @param float       $standardFloor      the target's floor before any order lowered it
     * @param string|null $loweringOrderRef   order that lowered the floor below the standard one, or null
     * @param float|null  $ownTargetCeiling   a price the ladder aims at for the target, which also caps the band
     * @param bool        $holdCurrent        the night already sells well: the ceiling is not pushed below the
     *                                        current price (capped at the dearer reference)
     */
    public function __construct(
        public readonly float $ownFloor,
        public readonly float $standardFloor,
        public readonly ?string $loweringOrderRef = null,
        public readonly ?float $ownTargetCeiling = null,
        public readonly bool $holdCurrent = false,
    ) {
    }

    public static function ofFloor(float $floor): self
    {
        return new self($floor, $floor);
    }

    /**
     * The order the item must cite: the lowering order, when it decided the lower edge.
     */
    public function forcedOrderRef(float $low): ?string
    {
        if ($this->loweringOrderRef === null) {
            return null;
        }

        return max($low, $this->ownFloor) < $this->standardFloor - self::ORDER_LOWERING_MIN ? $this->loweringOrderRef : null;
    }
}
