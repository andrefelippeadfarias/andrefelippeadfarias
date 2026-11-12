<?php

declare(strict_types=1);

namespace Ritmo\Domain\Plan\Validation;

use Ritmo\Domain\Config\Holiday;
use Ritmo\Domain\Model\NightType;
use Ritmo\Domain\Model\RoomType;
use Ritmo\Domain\Plan\CalendarNight;
use Ritmo\Domain\Plan\NightState;
use Ritmo\Domain\Plan\PercentItem;
use Ritmo\Domain\Plan\PlanItem;

/**
 * What every check of one item reads: the item, its room, its amounts as the pricing system will
 * apply them (whole percentages of the base), the night, what is in force and the order it cites.
 */
final class ItemFacts
{
    public readonly PlanItem $item;

    /**
     * @param NightState|null    $state   what is in force: the item's own record, else the fresh calendar's
     * @param CalendarNight|null $fresh   the night in the fresh calendar
     */
    public function __construct(
        public readonly int $index,
        public readonly PercentItem $percent,
        public readonly RoomType $room,
        public readonly int $daysOut,
        public readonly NightType $nightType,
        public readonly ?Holiday $holiday,
        public readonly ItemAuthority $authority,
        public readonly ?CalendarNight $fresh,
        public readonly ?NightState $state,
    ) {
        $this->item = $percent->item;
    }

    /**
     * The floor as the pricing system applies it (whole currency), or null.
     */
    public function floor(): ?float
    {
        return $this->percent->minAmount();
    }

    /**
     * The lowest price the item allows: its floor, or its ceiling when it sets no floor.
     */
    public function lowest(): ?float
    {
        return $this->percent->minAmount() ?? $this->percent->maxAmount();
    }

    /**
     * The current price of the night: the item's record, else the fresh calendar.
     */
    public function current(): ?float
    {
        return $this->state?->price ?? $this->fresh?->price;
    }

    /**
     * The floor in force for the night: the item's record, else the fresh calendar.
     */
    public function floorInForce(): ?float
    {
        return $this->state?->minPrice ?? $this->fresh?->floor;
    }
}
