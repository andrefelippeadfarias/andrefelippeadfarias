<?php

declare(strict_types=1);

namespace Ritmo\Domain\Relations\Validate;

/**
 * A room-night of the fresh calendar the validator reads before writing.
 */
final class FreshNight
{
    /**
     * @param int|null $freeUnits null when unknown (counts as free)
     * @param int|null $total     null when unknown (no proof of occupancy)
     */
    public function __construct(
        public readonly ?float $price,
        public readonly ?int $freeUnits = null,
        public readonly int $sold = 0,
        public readonly ?int $total = null,
    ) {
    }
}
