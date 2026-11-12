<?php

declare(strict_types=1);

namespace Ritmo\Domain\Report;

use DateTimeImmutable;

/**
 * Occupancy of a property and of each of its room types in the windows of the occupancy goals.
 */
final class OccupancyReport
{
    /**
     * @param array<string, array<int, WindowOccupancy>> $rooms    room code => window days => occupancy
     * @param array<int, WindowOccupancy>                $property window days => occupancy of the whole property
     */
    public function __construct(
        public readonly string $propertyId,
        public readonly DateTimeImmutable $today,
        public readonly array $rooms,
        public readonly array $property,
    ) {
    }

    /**
     * The windows of the property that are under their goal, shortest first.
     *
     * @return list<WindowOccupancy>
     */
    public function windowsUnderGoal(): array
    {
        return array_values(array_filter($this->property, static fn (WindowOccupancy $window): bool => $window->meetsGoal() === false));
    }
}
