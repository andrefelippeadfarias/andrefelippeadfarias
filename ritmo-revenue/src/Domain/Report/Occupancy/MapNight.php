<?php

declare(strict_types=1);

namespace Ritmo\Domain\Report\Occupancy;

use DateTimeImmutable;

/**
 * One night of the occupancy map: weekday, holiday, each room's cell and the free units of the
 * night.
 */
final class MapNight
{
    /**
     * @param int                     $weekday Monday = 0 ... Sunday = 6
     * @param array<string, MapCell>  $cells   room code => cell
     */
    public function __construct(
        public readonly DateTimeImmutable $night,
        public readonly int $weekday,
        public readonly ?string $holiday,
        public readonly array $cells,
        public readonly int $free,
    ) {
    }
}
