<?php

declare(strict_types=1);

namespace Ritmo\Domain\Report\Occupancy;

use InvalidArgumentException;
use Ritmo\Domain\Model\RoomType;

/**
 * A group of rooms shown in the occupancy report: the rooms carrying any of its tags
 * (reports.occupancy.groups[]; divergence RP-X7).
 */
final class OccupancyGroup
{
    /**
     * @param list<string> $tags
     *
     * @throws InvalidArgumentException without a label or a tag
     */
    public function __construct(public readonly string $label, public readonly array $tags)
    {
        if (trim($label) === '' || $tags === []) {
            throw new InvalidArgumentException('An occupancy group needs a label and at least one tag.');
        }
    }

    public function contains(RoomType $room): bool
    {
        foreach ($this->tags as $tag) {
            if ($room->hasTag($tag)) {
                return true;
            }
        }

        return false;
    }
}
