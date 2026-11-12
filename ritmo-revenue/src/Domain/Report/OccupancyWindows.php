<?php

declare(strict_types=1);

namespace Ritmo\Domain\Report;

use DateTimeImmutable;
use DateTimeInterface;
use Ritmo\Domain\Config\RuleSet;
use Ritmo\Domain\Model\InventoryNight;
use Ritmo\Domain\Model\Property;
use Ritmo\Domain\Model\RoomType;
use Ritmo\Domain\Support\Days;
use Ritmo\Domain\Support\Num;

/**
 * Units sold over capacity, per room type and for the whole property, in the windows of the
 * occupancy goals (the next 7, 15, 30 ... nights, today included), from the PMS inventory.
 *
 * A night counts its units minus the units still available, between zero and the units of the
 * room (an overbooked night counts as full). Nights the PMS does not report are left out of both
 * the sold units and the capacity.
 *
 * Reference: ocupacao.calcular (the windows) with the goals of the configuration.
 */
final class OccupancyWindows
{
    public function __construct(private readonly RuleSet $rules)
    {
    }

    /**
     * @param list<RoomType>                      $rooms     room types of the property; others are ignored
     * @param array<string, list<InventoryNight>> $inventory room code => nights of the PMS calendar
     */
    public function measure(Property $property, array $rooms, array $inventory, DateTimeInterface $today): OccupancyReport
    {
        $goals = $this->rules->occupancyGoalsFor($property);
        $first = Days::normalize($today);

        $byRoom = [];
        $total = array_fill_keys(array_keys($goals), ['sold' => 0, 'capacity' => 0]);
        foreach ($rooms as $room) {
            if ($room->propertyId !== $property->id) {
                continue;
            }
            $counts = $this->count($room, $inventory[$room->code] ?? [], $first, array_keys($goals));
            foreach ($counts as $days => $count) {
                $byRoom[$room->code][$days] = self::window($days, $count, $goals[$days]);
                $total[$days]['sold'] += $count['sold'];
                $total[$days]['capacity'] += $count['capacity'];
            }
        }

        $ofProperty = [];
        foreach ($total as $days => $count) {
            $ofProperty[$days] = self::window($days, $count, $goals[$days]);
        }

        return new OccupancyReport($property->id, $first, $byRoom, $ofProperty);
    }

    /**
     * Units sold and capacity of a room in each window.
     *
     * @param list<InventoryNight> $nights
     * @param list<int>            $windows window lengths in days
     *
     * @return array<int, array{sold: int, capacity: int}>
     */
    private function count(RoomType $room, array $nights, DateTimeImmutable $today, array $windows): array
    {
        $counts = array_fill_keys($windows, ['sold' => 0, 'capacity' => 0]);
        $seen = [];
        foreach ($nights as $night) {
            $daysOut = Days::diff($today, $night->date);
            if ($daysOut < 0 || isset($seen[$daysOut])) {
                continue;
            }
            $seen[$daysOut] = true;
            $sold = max(0, min($room->units, $room->units - $night->available));
            foreach ($windows as $days) {
                if ($daysOut < $days) {
                    $counts[$days]['sold'] += $sold;
                    $counts[$days]['capacity'] += $room->units;
                }
            }
        }

        return $counts;
    }

    /**
     * @param array{sold: int, capacity: int} $count
     */
    private static function window(int $days, array $count, float $goal): WindowOccupancy
    {
        $percent = $count['capacity'] > 0 ? Num::roundHalfEven(100 * $count['sold'] / $count['capacity'], 1) : null;

        return new WindowOccupancy($days, $count['sold'], $count['capacity'], $percent, $goal);
    }
}
