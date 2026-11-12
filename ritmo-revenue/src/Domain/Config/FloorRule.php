<?php

declare(strict_types=1);

namespace Ritmo\Domain\Config;

use Ritmo\Domain\Model\NightType;
use Ritmo\Domain\Model\PricePlan;
use Ritmo\Domain\Model\RoomType;

/**
 * A configured floor: an amount in a price plan, for some night types and some rooms.
 */
final class FloorRule
{
    /**
     * @param float           $amount     lowest price, expressed in $pricePlan; zero means the rule is off
     * @param list<NightType> $nightTypes night types the floor applies to
     * @param list<string>    $roomTags   the room must have every one of these tags; empty means every room
     */
    public function __construct(
        public readonly float $amount,
        public readonly PricePlan $pricePlan,
        public readonly array $nightTypes,
        public readonly array $roomTags = [],
    ) {
    }

    /**
     * True when the rule is on and covers that night type and room.
     */
    public function appliesTo(NightType $nightType, RoomType $room): bool
    {
        return $this->amount > 0
            && in_array($nightType, $this->nightTypes, true)
            && $room->hasAllTags($this->roomTags);
    }
}
