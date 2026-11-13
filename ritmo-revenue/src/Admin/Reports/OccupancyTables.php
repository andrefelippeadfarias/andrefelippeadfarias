<?php

declare(strict_types=1);

namespace Ritmo\Admin\Reports;

use Ritmo\Domain\Report\Occupancy\MapNight;
use Ritmo\Domain\Report\Occupancy\OccupancyRow;
use Ritmo\Domain\Report\Occupancy\RowKind;
use Ritmo\Domain\Support\Days;

/**
 * The occupancy windows table and the night map (spec 06, 2.2).
 */
final class OccupancyTables
{
    private function __construct()
    {
    }

    /**
     * @param list<OccupancyRow>    $rows
     * @param array<string, string> $names room code or property id => name
     */
    public static function windows(array $rows, array $names): ReportTable
    {
        $out = [];
        foreach ($rows as $row) {
            foreach ($row->windows as $days => $w) {
                $label = $row->kind === RowKind::Site ? __('All properties', 'ritmo-revenue') : ($names[$row->key] ?? $row->key);
                $out[] = [$label, ReportsText::rowKind($row->kind), $days, $w->sold, $w->capacity, $w->percent, $w->goal, $w->meetsGoal() === null ? '' : ($w->meetsGoal() ? __('on goal', 'ritmo-revenue') : __('below goal', 'ritmo-revenue'))];
            }
        }

        return new ReportTable('occupancy', __('Occupancy of the next nights', 'ritmo-revenue'), [
            [__('Row', 'ritmo-revenue'), Cell::Text], [__('Kind', 'ritmo-revenue'), Cell::Text], [__('Next nights', 'ritmo-revenue'), Cell::Count],
            [__('Room nights sold', 'ritmo-revenue'), Cell::Count], [__('Room nights for sale', 'ritmo-revenue'), Cell::Count], [__('Occupancy', 'ritmo-revenue'), Cell::Percent],
            [__('Goal', 'ritmo-revenue'), Cell::Percent], [__('Against the goal', 'ritmo-revenue'), Cell::Text],
        ], $out);
    }

    /**
     * @param list<MapNight>        $map
     * @param array<string, string> $roomNames
     */
    public static function map(array $map, array $roomNames): ReportTable
    {
        $rows = [];
        foreach ($map as $night) {
            foreach ($night->cells as $room => $cell) {
                $rows[] = [Days::key($night->night), ReportsText::weekday($night->weekday), $night->holiday ?? '', $roomNames[$room] ?? $room, $cell->free, $cell->sold, $cell->total, $cell->price, $cell->sentPrice, $cell->minStay];
            }
        }

        return new ReportTable('occupancy-map', __('Free rooms night by night', 'ritmo-revenue'), [
            [__('Night', 'ritmo-revenue'), Cell::Date], [__('Weekday', 'ritmo-revenue'), Cell::Text], [__('Holiday', 'ritmo-revenue'), Cell::Text], [__('Room', 'ritmo-revenue'), Cell::Text],
            [__('Free', 'ritmo-revenue'), Cell::Count], [__('Sold', 'ritmo-revenue'), Cell::Count], [__('Units', 'ritmo-revenue'), Cell::Count],
            [__('Calculated price', 'ritmo-revenue'), Cell::Money], [__('Sent price', 'ritmo-revenue'), Cell::Money], [__('Minimum stay', 'ritmo-revenue'), Cell::Count],
        ], $rows);
    }
}
