<?php

declare(strict_types=1);

namespace Ritmo\Admin\Reports;

use DateTimeImmutable;
use Ritmo\Admin\Components\NightDate;
use Ritmo\Application\Report\ReportScope;
use Ritmo\Application\Report\ReportService;
use Ritmo\Domain\Report\Month\Period;
use Ritmo\Domain\Support\Days;

/**
 * What each tab of the Reports screen shows, built once for the screen and for its CSV: a summary
 * sentence (its numbers are those of the tables), the tables and, for the weekly stays, the bars.
 */
final class ReportViews
{
    public const TABS = ['weekly', 'month', 'channels', 'rooms', 'review', 'targets', 'occupancy'];

    private function __construct()
    {
    }

    /**
     * @param array<string, string> $roomNames
     *
     * @return array{summary: string, tables: list<ReportTable>, chart: array<string, mixed>|null}
     */
    public static function of(string $tab, ReportService $reports, ReportScope $scope, DateTimeImmutable $now, string $month, array $roomNames): array
    {
        $currency = $scope->property->currency;
        $today = Days::localDate($now, $scope->property->timezone);

        return match ($tab) {
            'month' => self::month($reports, $scope, $now, $month, $roomNames),
            'channels', 'rooms' => self::shares($tab, $reports, $scope, $now, $roomNames),
            'review' => self::review($reports, $scope, $now),
            'targets' => self::targets($reports, $scope, $now),
            'occupancy' => self::occupancy($reports, $scope, $today, $roomNames),
            default => self::weekly($reports, $scope, $now, $currency),
        };
    }

    /**
     * @return array{summary: string, tables: list<ReportTable>, chart: array<string, mixed>|null}
     */
    private static function weekly(ReportService $reports, ReportScope $scope, DateTimeImmutable $now, string $currency): array
    {
        $panel = $reports->weekly($scope, $now);
        $bars = [];
        foreach ($panel->stays as $week) {
            $bars[] = ['label' => NightDate::short($week->monday), 'value' => (float) $week->occupancy, 'text' => Cell::Fraction->format($week->occupancy, $currency)];
        }

        return [
            'summary' => WeeklyTables::summary($panel, $currency),
            'tables' => [WeeklyTables::made($panel), WeeklyTables::stays($panel), WeeklyTables::goals($panel)],
            'chart' => BarChart::of($bars),
        ];
    }

    /**
     * @param array<string, string> $roomNames
     *
     * @return array{summary: string, tables: list<ReportTable>, chart: array<string, mixed>|null}
     */
    private static function month(ReportService $reports, ReportScope $scope, DateTimeImmutable $now, string $month, array $roomNames): array
    {
        $report = $reports->month($scope, $month, $now);

        return ['summary' => MonthTables::summary($report, $scope->property->id, $scope->property->currency), 'tables' => [MonthTables::of($report, $scope->property->id, $roomNames)], 'chart' => null];
    }

    /**
     * @param array<string, string> $roomNames
     *
     * @return array{summary: string, tables: list<ReportTable>, chart: array<string, mixed>|null}
     */
    private static function shares(string $tab, ReportService $reports, ReportScope $scope, DateTimeImmutable $now, array $roomNames): array
    {
        $table = WeeklyTables::shares($reports->weekly($scope, $now), $tab === 'channels', $roomNames);
        $total = $table->totals;
        $summary = $total === null
            ? __('No booking was made in the last 28 days.', 'ritmo-revenue')
            : sprintf(
                /* translators: %1$d: bookings, %2$s: revenue */
                _n('Last 28 days: %1$d booking made for %2$s.', 'Last 28 days: %1$d bookings made for %2$s.', (int) $total[1], 'ritmo-revenue'),
                (int) $total[1],
                Cell::Money->format((float) $total[5], $scope->property->currency)
            );

        return ['summary' => $summary, 'tables' => [$table], 'chart' => null];
    }

    /**
     * @return array{summary: string, tables: list<ReportTable>, chart: array<string, mixed>|null}
     */
    private static function review(ReportService $reports, ReportScope $scope, DateTimeImmutable $now): array
    {
        $review = $reports->review($scope, $now);

        return ['summary' => ReviewTables::summary($review), 'tables' => [ReviewTables::review($review)], 'chart' => null];
    }

    /**
     * @return array{summary: string, tables: list<ReportTable>, chart: array<string, mixed>|null}
     */
    private static function targets(ReportService $reports, ReportScope $scope, DateTimeImmutable $now): array
    {
        $targets = $reports->targets($scope, $now);

        return ['summary' => __('The forecast multiplies last year\'s market by the growth and the index of each scenario, and is never below what is already on the books.', 'ritmo-revenue'), 'tables' => [ReviewTables::targets($targets['forecast'], $targets['targets'])], 'chart' => null];
    }

    /**
     * @param array<string, string> $roomNames
     *
     * @return array{summary: string, tables: list<ReportTable>, chart: array<string, mixed>|null}
     */
    private static function occupancy(ReportService $reports, ReportScope $scope, DateTimeImmutable $today, array $roomNames): array
    {
        $result = $reports->occupancy($scope, $today);
        $names = $roomNames + [$scope->property->id => $scope->property->name];

        return [
            'summary' => __('Occupancy counts the units booked; a night closed in the PMS without a booking is not a sale.', 'ritmo-revenue'),
            'tables' => [OccupancyTables::windows($result['rows'], $names), OccupancyTables::map($result['map'], $roomNames)],
            'chart' => null,
        ];
    }

    /**
     * The month asked for ("YYYY-MM"), else the current one at the property.
     */
    public static function monthOf(string $asked, DateTimeImmutable $today): string
    {
        return preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $asked) === 1 ? $asked : Period::keyOf($today);
    }
}
