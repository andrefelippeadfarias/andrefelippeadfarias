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
use Ritmo\Domain\Support\Num;

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
     * @param bool               $afterDeletion the plan deletes the night's rule first: the item writes
     *                                          it again, so what was in force says nothing of its effect
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
        public readonly bool $afterDeletion = false,
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
     * The lowest current price known for the night, of the item's record and the fresh calendar:
     * what a floor must not pass to leave tonight's price where it is.
     */
    public function lowestCurrent(): ?float
    {
        $prices = array_filter([$this->state?->price, $this->fresh?->price], static fn (?float $p): bool => $p !== null);

        return $prices === [] ? null : min($prices);
    }

    /**
     * The highest floor in force known for the night, of the item's record and the fresh calendar:
     * what a ceiling without a floor must not go under.
     */
    public function floorInForce(): ?float
    {
        $floors = array_filter([$this->state?->minPrice, $this->fresh?->floor], static fn (?float $p): bool => $p !== null);

        return $floors === [] ? null : max($floors);
    }

    /**
     * The item writes again the floor in force, as the same whole percentage of the base, by its
     * record and by the fresh calendar: the floor of the night does not change.
     */
    public function rewritesFloorInForce(): bool
    {
        $percent = $this->percent->minPercent;
        if ($percent === null || $this->state?->minPercent !== (float) $percent) {
            return false;
        }
        $fresh = $this->fresh?->floor;

        return $fresh === null || Num::floorToPercent($fresh, $this->room->base) === $percent;
    }
}
