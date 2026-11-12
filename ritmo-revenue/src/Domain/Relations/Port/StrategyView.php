<?php

declare(strict_types=1);

namespace Ritmo\Domain\Relations\Port;

use DateTimeImmutable;

/**
 * What the sales ladder (strategy) says about a room-night.
 */
interface StrategyView
{
    /** The night is priced by a ladder that keeps the floor equal to the ceiling. */
    public function pinsFloorToCeiling(string $room, DateTimeImmutable $night): bool;

    /** The ladder fixes the price of the room tonight. */
    public function governs(string $room, DateTimeImmutable $night): bool;
}
