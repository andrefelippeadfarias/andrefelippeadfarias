<?php

declare(strict_types=1);

namespace Ritmo\Domain\Relations\Coherence;

use DateTimeImmutable;

/**
 * Sold units of a set of rooms on a night, against the configured units of the set.
 *
 * Every room of the set counts its configured units in the total; a room without a calendar row
 * that night counts nothing as sold (7 of 7 sold in one room and the other missing is 7 of 9).
 * Overbooking is capped at the units of the room.
 */
final class OccupancyCount
{
    /**
     * @param list<string> $rooms
     *
     * @return array{int, int} sold, units
     */
    public static function of(NightFacts $facts, array $rooms, DateTimeImmutable $night): array
    {
        $sold = 0;
        $units = 0;
        foreach ($rooms as $room) {
            if (!$facts->hasRoom($room)) {
                continue;
            }
            $units += $facts->room($room)->units;
            $row = $facts->row($room, $night);
            if ($row !== null) {
                $sold += min($row->sold, $row->total);
            }
        }

        return [$sold, $units];
    }
}
