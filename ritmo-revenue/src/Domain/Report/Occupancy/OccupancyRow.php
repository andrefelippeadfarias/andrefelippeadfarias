<?php

declare(strict_types=1);

namespace Ritmo\Domain\Report\Occupancy;

use Ritmo\Domain\Report\WindowOccupancy;

/**
 * One row of the occupancy windows table: a room, the site, a tag group or a property, with its
 * occupancy in each window.
 */
final class OccupancyRow
{
    /**
     * @param string                       $key     room code, property id, group label or '' for the site
     * @param array<int, WindowOccupancy>  $windows window days => occupancy
     */
    public function __construct(
        public readonly RowKind $kind,
        public readonly string $key,
        public readonly array $windows,
    ) {
    }
}
