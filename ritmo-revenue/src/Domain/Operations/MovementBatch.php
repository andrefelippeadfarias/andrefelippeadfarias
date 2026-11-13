<?php

declare(strict_types=1);

namespace Ritmo\Domain\Operations;

/**
 * What a movement read brought: the movements, the booking versions to record as processed and
 * how many bookings belong to rooms the property did not map.
 */
final class MovementBatch
{
    /**
     * @param list<Movement>                                   $movements
     * @param list<array{0: BookingVersion, 1: MovementKind}> $versions  versions processed now
     */
    public function __construct(
        public readonly array $movements,
        public readonly array $versions,
        public readonly int $unmapped,
    ) {
    }

    /**
     * @return array<string, int> kind => count (new, cancelled, modified)
     */
    public function counts(): array
    {
        $counts = [MovementKind::New->value => 0, MovementKind::Cancelled->value => 0, MovementKind::Modified->value => 0];
        foreach ($this->movements as $movement) {
            ++$counts[$movement->kind->value];
        }

        return $counts;
    }
}
