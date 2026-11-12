<?php

declare(strict_types=1);

namespace Ritmo\Domain\Report;

use DateTimeImmutable;

/**
 * A night on which a room type has more bookings than units.
 */
final class OverbookedNight
{
    /**
     * @param int $available units available as the PMS reports them: below zero
     */
    public function __construct(
        public readonly string $roomCode,
        public readonly DateTimeImmutable $night,
        public readonly int $available,
    ) {
    }

    /**
     * How many units were sold beyond the units of the room.
     */
    public function unitsOver(): int
    {
        return -$this->available;
    }
}
