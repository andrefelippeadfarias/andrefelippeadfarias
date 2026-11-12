<?php

declare(strict_types=1);

namespace Ritmo\Domain\Report;

use Ritmo\Domain\Model\InventoryNight;

/**
 * Finds the overbooked nights of the PMS inventory: the nights whose availability is negative.
 */
final class OverbookingDetector
{
    /**
     * @param array<string, list<InventoryNight>> $inventory room code => nights of the PMS calendar
     *
     * @return list<OverbookedNight> by night, then by room code
     */
    public function detect(array $inventory): array
    {
        $found = [];
        foreach ($inventory as $roomCode => $nights) {
            foreach ($nights as $night) {
                if ($night->available < 0) {
                    $found[] = new OverbookedNight((string) $roomCode, $night->date, $night->available);
                }
            }
        }
        usort($found, static fn (OverbookedNight $a, OverbookedNight $b): int => [$a->night, $a->roomCode] <=> [$b->night, $b->roomCode]);

        return $found;
    }
}
