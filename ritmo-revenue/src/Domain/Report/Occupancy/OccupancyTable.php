<?php

declare(strict_types=1);

namespace Ritmo\Domain\Report\Occupancy;

use DateTimeImmutable;
use Ritmo\Domain\Model\InventoryNight;
use Ritmo\Domain\Model\RoomType;
use Ritmo\Domain\Report\WindowOccupancy;
use Ritmo\Domain\Support\Days;
use Ritmo\Domain\Support\Num;

/**
 * Occupancy in the windows of the goals (the next 7, 15, 30 ... nights, today included) for each
 * room, the site (when it has more than one property), each tag group and each property, from the
 * PMS inventory and, when read, the booked units (RP-1, RP-2).
 *
 * A night the PMS does not report is left out of the sold units and the capacity. Percent: one
 * decimal, half to even; null without capacity.
 *
 * Reference: ocupacao.calcular ("janelas").
 */
final class OccupancyTable
{
    private function __construct()
    {
    }

    /**
     * @param list<RoomType>                          $rooms     in display order
     * @param array<string, list<InventoryNight>>     $inventory room code => nights
     * @param array<string, array<string, int>>|null  $booked    room code => 'YYYY-MM-DD' => units booked; null when not read
     * @param array<string, array<int, float>>        $goals     property id => window days => goal percent
     * @param array<int, float>                       $siteGoals window days => goal percent of the site and the groups
     * @param list<OccupancyGroup>                    $groups
     *
     * @return list<OccupancyRow>
     */
    public static function of(array $rooms, array $inventory, ?array $booked, DateTimeImmutable $today, array $goals, array $siteGoals, array $groups): array
    {
        $windows = array_keys($siteGoals);
        $counts = [];
        foreach ($rooms as $room) {
            $counts[$room->code] = self::count($room, $inventory[$room->code] ?? [], $booked === null ? null : ($booked[$room->code] ?? []), Days::normalize($today), $windows);
        }
        $rows = [];
        foreach ($rooms as $room) {
            $rows[] = self::row(RowKind::Room, $room->code, [$counts[$room->code]], $goals[$room->propertyId] ?? $siteGoals);
        }
        $properties = array_values(array_unique(array_map(static fn (RoomType $r): string => $r->propertyId, $rooms)));
        if (count($properties) > 1) {
            $rows[] = self::row(RowKind::Site, '', array_values($counts), $siteGoals);
        }
        foreach ($groups as $group) {
            $members = array_filter($rooms, static fn (RoomType $r): bool => $group->contains($r));
            $rows[] = self::row(RowKind::Group, $group->label, array_map(static fn (RoomType $r): array => $counts[$r->code], array_values($members)), $siteGoals);
        }
        foreach ($properties as $propertyId) {
            $members = array_filter($rooms, static fn (RoomType $r): bool => $r->propertyId === $propertyId);
            $rows[] = self::row(RowKind::Property, $propertyId, array_map(static fn (RoomType $r): array => $counts[$r->code], array_values($members)), $goals[$propertyId] ?? $siteGoals);
        }

        return $rows;
    }

    /**
     * @param list<InventoryNight>     $nights
     * @param array<string, int>|null  $booked
     * @param list<int>                $windows
     *
     * @return array<int, array{sold: int, capacity: int}>
     */
    private static function count(RoomType $room, array $nights, ?array $booked, DateTimeImmutable $today, array $windows): array
    {
        $counts = array_fill_keys($windows, ['sold' => 0, 'capacity' => 0]);
        $seen = [];
        foreach ($nights as $night) {
            $daysOut = Days::diff($today, $night->date);
            if ($daysOut < 0 || isset($seen[$daysOut])) {
                continue;
            }
            $seen[$daysOut] = true;
            $state = RoomNight::of($room->units, $night, $booked === null ? null : ($booked[Days::key($night->date)] ?? 0));
            if (!$state->counts()) {
                continue;
            }
            foreach ($windows as $days) {
                if ($daysOut < $days) {
                    $counts[$days]['sold'] += $state->sold;
                    $counts[$days]['capacity'] += $state->total;
                }
            }
        }

        return $counts;
    }

    /**
     * @param list<array<int, array{sold: int, capacity: int}>> $members
     * @param array<int, float>                                 $goals
     */
    private static function row(RowKind $kind, string $key, array $members, array $goals): OccupancyRow
    {
        $windows = [];
        foreach ($goals as $days => $goal) {
            $sold = $capacity = 0;
            foreach ($members as $counts) {
                $sold += $counts[$days]['sold'] ?? 0;
                $capacity += $counts[$days]['capacity'] ?? 0;
            }
            $percent = $capacity > 0 ? Num::roundHalfEven(100 * $sold / $capacity, 1) : null;
            $windows[$days] = new WindowOccupancy($days, $sold, $capacity, $percent, $goal);
        }

        return new OccupancyRow($kind, $key, $windows);
    }
}
