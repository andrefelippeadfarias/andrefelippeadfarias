<?php

declare(strict_types=1);

namespace Ritmo\Domain\Report\Occupancy;

/**
 * One room on one night of the occupancy map. Unknown night: everything null.
 */
final class MapCell
{
    public function __construct(
        public readonly ?int $free = null,
        public readonly ?int $sold = null,
        public readonly ?int $total = null,
        public readonly ?float $price = null,
        public readonly ?float $sentPrice = null,
        public readonly ?int $minStay = null,
        public readonly ?string $demand = null,
    ) {
    }

    public function isKnown(): bool
    {
        return $this->free !== null;
    }
}
