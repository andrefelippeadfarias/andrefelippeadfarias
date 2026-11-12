<?php

declare(strict_types=1);

namespace Ritmo\Domain\Pricing;

use Ritmo\Domain\Config\RuleSet;
use Ritmo\Domain\Model\RoomType;

/**
 * Chooses what the pace of a room is measured over.
 *
 * A room type with very few units is 0%, 50% or 100% sold, which says little against a sales
 * curve. With "pace.group_pace" on, such a room is read with its group: the room types of the
 * same property that have the configured tags, itself included only if it has them.
 *
 * Reference: pace.ocupacao_pousada and its use in pace._plano.
 */
final class PaceBasis
{
    public function __construct(private readonly RuleSet $rules)
    {
    }

    /**
     * The units sold and on sale of the group a room is read with, or null when the room is
     * read on its own: it has more units than "pace.group_pace.max_units", or its group has
     * nothing on sale that night.
     *
     * An overbooked room counts as full.
     *
     * @param list<RoomType>                 $rooms room types of the property
     * @param array<string, array{int, int}> $sales room code => [units sold, units on sale] on the night;
     *                                              rooms without data for the night are absent
     *
     * @return array{int, int}|null [units sold, units on sale]; pass them as the pace basis of the NightContext
     */
    public function groupFor(RoomType $room, array $rooms, array $sales): ?array
    {
        if ($room->units > $this->rules->groupPaceMaxUnits()) {
            return null;
        }

        $tags = $this->rules->groupPaceRoomTags();
        $sold = 0;
        $units = 0;
        foreach ($rooms as $other) {
            if ($other->propertyId !== $room->propertyId || !$other->hasAllTags($tags) || !isset($sales[$other->code])) {
                continue;
            }
            [$roomSold, $roomUnits] = $sales[$other->code];
            $sold += min($roomSold, $roomUnits);
            $units += $roomUnits;
        }

        return $units > 0 ? [$sold, $units] : null;
    }
}
