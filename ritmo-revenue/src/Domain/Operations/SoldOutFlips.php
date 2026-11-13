<?php

declare(strict_types=1);

namespace Ritmo\Domain\Operations;

use DateTimeImmutable;
use Ritmo\Domain\Model\InventoryNight;
use Ritmo\Domain\Support\Days;

/**
 * The nights from today to today + $days whose sold-out state (available 0 or less, as
 * Occupancy::soldOut) differs between the inventory read now and the latest read before it. A
 * night without an earlier read is no flip: there is nothing to compare with.
 */
final class SoldOutFlips
{
    /**
     * @param array<string, array<string, InventoryNight>> $current  room code => 'YYYY-MM-DD' => night, read now
     * @param array<string, array<string, InventoryNight>> $previous the same, as read before this run
     *
     * @return list<SoldOutFlip> by room, then night
     */
    public static function find(array $current, array $previous, DateTimeImmutable $today, int $days): array
    {
        $flips = [];
        $last = Days::key(Days::add($today, $days));
        $first = Days::key($today);
        ksort($current);
        foreach ($current as $room => $nights) {
            ksort($nights);
            foreach ($nights as $key => $night) {
                $before = $previous[$room][$key] ?? null;
                if ($key < $first || $key > $last || $before === null) {
                    continue;
                }
                if (($night->available <= 0) !== ($before->available <= 0)) {
                    $flips[] = new SoldOutFlip((string) $room, $night->date, $night->available <= 0);
                }
            }
        }

        return $flips;
    }
}
