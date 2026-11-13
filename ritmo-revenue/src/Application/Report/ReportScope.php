<?php

declare(strict_types=1);

namespace Ritmo\Application\Report;

use Ritmo\Domain\Model\Property;
use Ritmo\Domain\Model\RoomType;

/**
 * One property as the reports read it: its rooms in display order, its capacity (the units of its
 * rooms) and the map of its rooms to it.
 */
final class ReportScope
{
    /**
     * @param list<RoomType> $rooms
     */
    public function __construct(public readonly Property $property, public readonly array $rooms)
    {
    }

    /**
     * @return array<string, int> property id => units
     */
    public function capacities(): array
    {
        return [$this->property->id => array_sum(array_map(static fn (RoomType $r): int => $r->units, $this->rooms))];
    }

    /**
     * @return array<string, string> room code => property id
     */
    public function roomProperty(): array
    {
        $out = [];
        foreach ($this->rooms as $room) {
            $out[$room->code] = $room->propertyId;
        }

        return $out;
    }
}
