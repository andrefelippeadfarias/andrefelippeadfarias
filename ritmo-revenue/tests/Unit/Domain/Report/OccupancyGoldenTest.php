<?php

declare(strict_types=1);

namespace Ritmo\Tests\Unit\Domain\Report;

use PHPUnit\Framework\TestCase;
use Ritmo\Domain\Model\InventoryNight;
use Ritmo\Domain\Model\PriceNight;
use Ritmo\Domain\Report\Occupancy\OccupancyGroup;
use Ritmo\Domain\Report\Occupancy\OccupancyMap;
use Ritmo\Domain\Report\Occupancy\OccupancyRow;
use Ritmo\Domain\Report\Occupancy\OccupancyTable;
use Ritmo\Domain\Report\Occupancy\RowKind;
use Ritmo\Domain\Report\WindowOccupancy;
use Ritmo\Domain\Support\Days;
use Ritmo\Tests\Support\Fixtures;
use Ritmo\Tests\Support\Golden;

/**
 * ocupacao.calcular of M4 (blocked and unknown nights, the groups and the night map) against
 * OccupancyTable and OccupancyMap with the booked units (RP-1..3).
 *
 * The golden input is the pricing calendar; it becomes the plugin's inputs as spec 06 2.2 says:
 * booked = the sold units; available = 0 on a blocked night (user_price -1 or unbookable 1 with
 * units left), else units - sold; a missing row is a night the PMS does not report. Divergence
 * RP-X1 is in the source of "blocked", not in the numbers. The sent price -1 of the reference
 * (the channel's "unavailable") is no price in the plugin (null).
 */
final class OccupancyGoldenTest extends TestCase
{
    private const SITE = 'Hotel';
    private const TUB_GROUP = 'Suítes com banheira';

    public function testWindowsAndGroupsMatchCalcular(): void
    {
        $cases = ReportGolden::cases('ocupacao.calcular');
        self::assertCount(2, $cases);
        $preset = Fixtures::parityPreset();
        foreach ($cases as $n => $case) {
            [$inventory, $booked] = self::inputs($case['input']['entries']);
            $rows = OccupancyTable::of($preset->roomTypes, $inventory, $booked, Days::date($case['input']['hoje']), self::goals(), self::siteGoals(), [new OccupancyGroup(self::TUB_GROUP, ['tub'])]);
            $byName = [];
            foreach ($rows as $row) {
                $byName[self::nameOf($row)] = $row;
            }
            self::assertEqualsCanonicalizing(array_keys($case['expected']['janelas']), array_keys($byName), "case $n rows");
            foreach ($case['expected']['janelas'] as $name => $windows) {
                foreach ($windows as $days => $e) {
                    $w = $byName[$name]->windows[(int) $days];
                    self::assertWindow($e, $w, "case $n $name $days");
                    self::assertSame((float) $case['expected']['metas'][$days], $w->goal, "case $n $name $days goal");
                }
            }
        }
    }

    public function testTheNightMapMatchesNoites(): void
    {
        $preset = Fixtures::parityPreset();
        foreach (ReportGolden::cases('ocupacao.calcular') as $n => $case) {
            [$inventory, $booked, $pricing, $demand] = self::inputs($case['input']['entries']);
            $today = Days::date($case['input']['hoje']);
            $holidays = [];
            for ($i = 0; $i < $case['input']['n_noites']; ++$i) {
                $night = Days::add($today, $i);
                $holiday = $preset->rules->holidayOn($night);
                if ($holiday !== null) {
                    $holidays[Days::key($night)] = $holiday->name;
                }
            }
            $map = OccupancyMap::of($today, $case['input']['n_noites'], $preset->roomTypes, $inventory, $booked, $pricing, $holidays, $demand);
            self::assertCount(count($case['expected']['noites']), $map);
            foreach ($case['expected']['noites'] as $i => $e) {
                $night = $map[$i];
                $at = "case $n {$e['data']}";
                self::assertSame($e['data'], Days::key($night->night), $at);
                self::assertSame($e['feriado'], $night->holiday, "$at holiday");
                self::assertSame($e['livres_total'], $night->free, "$at free");
                foreach ($e['quartos'] as $room => $cell) {
                    $actual = $night->cells[$room];
                    self::assertSame($cell['livres'], $actual->free, "$at $room free");
                    if ($cell['livres'] === null) {
                        continue;
                    }
                    self::assertSame($cell['vendidas'], $actual->sold, "$at $room sold");
                    self::assertSame($cell['total'], $actual->total, "$at $room total");
                    self::assertEqualsWithDelta($cell['preco'], $actual->price, Golden::AMOUNT_TOLERANCE, "$at $room price");
                    self::assertSame($cell['enviado'] == -1 ? null : (float) $cell['enviado'], $actual->sentPrice, "$at $room sent");
                    self::assertSame($cell['min_stay'], $actual->minStay, "$at $room min stay");
                    self::assertSame($cell['demanda'], $actual->demand, "$at $room demand");
                }
            }
        }
    }

    public function testTheLevelOneGoldenStillPassesWithTheBookedUnits(): void
    {
        $preset = Fixtures::parityPreset();
        foreach (Golden::cases('ocupacao.calcular') as $n => $case) {
            $inventory = $booked = [];
            foreach ($preset->roomTypes as $room) {
                foreach ($case['input']['calendario'][$room->code] as $night => $occupancy) {
                    [$sold, $units] = array_map('intval', explode('/', $occupancy));
                    $inventory[$room->code][] = new InventoryNight(Days::date($night), $units - $sold);
                    $booked[$room->code][$night] = $sold;
                }
            }
            $rows = OccupancyTable::of($preset->roomTypes, $inventory, $booked, Days::date($case['input']['hoje']), self::goals(), self::siteGoals(), []);
            foreach ($rows as $row) {
                if ($row->kind === RowKind::Site) {
                    continue;
                }
                $name = $row->kind === RowKind::Property ? ReportGolden::name($row->key) : $row->key;
                foreach ($case['expected'][$name] as $days => $e) {
                    self::assertWindow($e, $row->windows[(int) $days], "level 1 case $n $name $days");
                }
            }
        }
    }

    public function testWithoutBookingsAClosedNightCountsAsSoldAsInM1(): void
    {
        $room = Fixtures::parityPreset()->roomTypes[2];
        self::assertSame(1, $room->units);
        $today = Days::date('2026-11-13');
        $inventory = [$room->code => [new InventoryNight($today, 0)]];
        $without = OccupancyTable::of([$room], $inventory, null, $today, [], [7 => 70.0], []);
        $with = OccupancyTable::of([$room], $inventory, [], $today, [], [7 => 70.0], []);
        self::assertSame([1, 1], [$without[0]->windows[7]->sold, $without[0]->windows[7]->capacity]);
        self::assertSame([0, 0], [$with[0]->windows[7]->sold, $with[0]->windows[7]->capacity]);
        self::assertNull($with[0]->windows[7]->percent);
    }

    public function testASinglePropertyHasNoSiteRow(): void
    {
        $preset = Fixtures::parityPreset();
        $rows = OccupancyTable::of($preset->roomTypesOf($preset->properties[1]->id), [], [], Days::date('2026-11-13'), [], [7 => 70.0], []);
        self::assertSame([RowKind::Room, RowKind::Room, RowKind::Room, RowKind::Property], array_map(static fn (OccupancyRow $r): RowKind => $r->kind, $rows));
    }

    /**
     * @param array<string, array{id: string, data: list<array<string, mixed>>}> $entries
     *
     * @return array{0: array<string, list<InventoryNight>>, 1: array<string, array<string, int>>, 2: array<string, list<PriceNight>>, 3: array<string, array<string, string>>}
     */
    private static function inputs(array $entries): array
    {
        $inventory = $booked = $pricing = $demand = [];
        foreach ($entries as $room => $entry) {
            foreach ($entry['data'] as $row) {
                [$sold, $units] = array_map('intval', explode('/', $row['multi_unit_occupancy']));
                $blocked = ($row['user_price'] == -1 || ($row['unbookable'] ?? 0) == 1) && $sold < $units;
                $night = Days::date($row['date']);
                $inventory[$room][] = new InventoryNight($night, $blocked ? 0 : $units - $sold);
                $booked[$room][$row['date']] = $sold;
                $sent = $row['user_price'] == -1 ? null : (float) $row['user_price'];
                $pricing[$room][] = new PriceNight($night, (float) $row['price'], null, null, $row['min_stay'], $sold, $units, false, $sent, null, ($row['unbookable'] ?? 0) == 1);
                $demand[$room][$row['date']] = $row['demand_desc'];
            }
        }

        return [$inventory, $booked, $pricing, $demand];
    }

    /**
     * @return array<string, array<int, float>>
     */
    private static function goals(): array
    {
        $preset = Fixtures::parityPreset();
        $out = [];
        foreach ($preset->properties as $property) {
            $out[$property->id] = $preset->rules->occupancyGoalsFor($property);
        }

        return $out;
    }

    /**
     * @return array<int, float>
     */
    private static function siteGoals(): array
    {
        return Fixtures::parityRules()->occupancyGoals();
    }

    private static function nameOf(OccupancyRow $row): string
    {
        return match ($row->kind) {
            RowKind::Site => self::SITE,
            RowKind::Property => ReportGolden::name($row->key),
            default => $row->key,
        };
    }

    /**
     * @param array{vendidas: int, total: int, pct: float|null} $e
     */
    private static function assertWindow(array $e, WindowOccupancy $w, string $where): void
    {
        self::assertSame($e['vendidas'], $w->sold, "$where sold");
        self::assertSame($e['total'], $w->capacity, "$where capacity");
        self::assertSame($e['pct'] === null ? null : (float) $e['pct'], $w->percent, "$where percent");
    }
}
