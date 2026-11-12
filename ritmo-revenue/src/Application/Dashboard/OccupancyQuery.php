<?php

declare(strict_types=1);

namespace Ritmo\Application\Dashboard;

use OutOfBoundsException;
use Ritmo\Domain\Config\RuleSet;
use Ritmo\Domain\Report\OccupancyWindows;
use Ritmo\Domain\Report\WindowOccupancy;
use Ritmo\Domain\Support\Clock;
use Ritmo\Domain\Support\DateRange;
use Ritmo\Domain\Support\Days;
use Ritmo\Infrastructure\Db\Repository\InventorySnapshotRepository;
use Ritmo\Infrastructure\Db\Repository\PropertyRepository;
use Ritmo\Infrastructure\Db\Repository\RoomTypeRepository;
use Ritmo\Infrastructure\Db\Repository\RuleSetRepository;

/**
 * Occupancy of a property in each goal window, from the latest PMS calendar the plugin stored.
 * It reads only the plugin's own tables: no external call.
 */
final class OccupancyQuery
{
    /** The window of the traffic light. */
    public const SIGNAL_WINDOW = 7;

    /** Nights read from the snapshots: enough for the longest goal window. */
    private const NIGHTS = 60;

    public function __construct(
        private readonly PropertyRepository $properties,
        private readonly RoomTypeRepository $roomTypes,
        private readonly RuleSetRepository $ruleSets,
        private readonly InventorySnapshotRepository $snapshots,
        private readonly Clock $clock,
    ) {
    }

    /**
     * @return array{property_id: string, today: string, captured_at: string|null, signal: string, signal_window: int, property: array<int, array<string, mixed>>, rooms: array<string, array<int, array<string, mixed>>>}
     *
     * @throws OutOfBoundsException when the property does not exist
     */
    public function forProperty(string $propertyId): array
    {
        $property = $this->properties->find($propertyId) ?? throw new OutOfBoundsException(sprintf('Unknown property "%s".', $propertyId));
        $rules = $this->ruleSets->current() ?? RuleSet::defaults();
        $rooms = $this->roomTypes->forProperty($property->id);
        $today = $this->clock->today($property->timezone);
        $range = DateRange::nights($today, self::NIGHTS);

        $inventory = [];
        $captured = null;
        foreach ($rooms as $room) {
            $inventory[$room->code] = array_values($this->snapshots->latest($room->code, $range->first, $range->last));
            $last = $this->snapshots->lastCapturedAt($room->code);
            if ($last !== null && ($captured === null || $last > $captured)) {
                $captured = $last;
            }
        }
        $report = (new OccupancyWindows($rules))->measure($property, $rooms, $inventory, $today);
        $window = $report->property[self::SIGNAL_WINDOW] ?? null;

        return [
            'property_id' => $property->id,
            'today' => Days::key($today),
            'captured_at' => $captured?->format(DATE_ATOM),
            'signal' => $window === null ? OccupancySignal::UNKNOWN : OccupancySignal::classify($window->percent, $window->goal),
            'signal_window' => self::SIGNAL_WINDOW,
            'property' => self::windows($report->property),
            'rooms' => array_map(self::windows(...), $report->rooms),
        ];
    }

    /**
     * @param array<int, WindowOccupancy> $windows
     *
     * @return array<int, array{sold: int, capacity: int, percent: float|null, goal: float, signal: string}>
     */
    private static function windows(array $windows): array
    {
        return array_map(static fn (WindowOccupancy $w): array => [
            'sold' => $w->sold,
            'capacity' => $w->capacity,
            'percent' => $w->percent,
            'goal' => $w->goal,
            'signal' => OccupancySignal::classify($w->percent, $w->goal),
        ], $windows);
    }
}
