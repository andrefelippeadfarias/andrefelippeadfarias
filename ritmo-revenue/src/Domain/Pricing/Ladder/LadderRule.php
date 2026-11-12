<?php

declare(strict_types=1);

namespace Ritmo\Domain\Pricing\Ladder;

use Ritmo\Domain\Model\RoomType;

/**
 * How a ladder prices some rooms on some night types: where it opens, where it stops, whether it
 * derives from another room, and which floors and drops apply.
 */
final class LadderRule
{
    /**
     * @param list<string> $nightTypes ruler night-type labels the rule prices
     * @param list<string> $rooms      room codes the rule prices
     * @param list<string> $roomTags   or every room carrying all these tags
     * @param bool         $capAtReference never open above the reference price stored for the room and night
     */
    public function __construct(
        public readonly array $nightTypes,
        public readonly array $rooms,
        public readonly array $roomTags,
        public readonly ?RulerPoint $opening,
        public readonly bool $capAtReference,
        public readonly ?RulerPoint $ceiling,
        public readonly ?Derivation $derive,
        public readonly bool $applyQualityFloor,
        public readonly bool $applyDrop,
    ) {
    }

    public function matches(RoomType $room, string $nightType): bool
    {
        if (!in_array($nightType, $this->nightTypes, true)) {
            return false;
        }

        return in_array($room->code, $this->rooms, true) || ($this->roomTags !== [] && $room->hasAllTags($this->roomTags));
    }
}
