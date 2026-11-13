<?php

declare(strict_types=1);

namespace Ritmo\Admin\Reports;

use Ritmo\Domain\Report\Review\ReviewResult;
use Ritmo\Domain\Report\Targets\MonthForecast;
use Ritmo\Domain\Report\Targets\PropertyTarget;
use Ritmo\Domain\Report\Targets\Scenario;

/**
 * The 12-month review (spec 06, 2.4) and the targets with the forecast (2.5).
 */
final class ReviewTables
{
    private function __construct()
    {
    }

    public static function review(ReviewResult $r): ReportTable
    {
        $rows = [];
        foreach ($r->rows as $row) {
            $rows[] = [ReportsText::month($row->month), $row->soldRevenue, $row->minimum, $row->achieved, $row->pickupSinceLast, $row->soldOccupancy, $row->marketSoldPercent, $row->marketLastYearPercent, ReportsText::reading($row->reading), $row->adr, $row->averagePrice, $row->priceChange];
        }

        return new ReportTable('review', __('The next 12 months', 'ritmo-revenue'), [
            [__('Month', 'ritmo-revenue'), Cell::Text], [__('Sold', 'ritmo-revenue'), Cell::Money], [__('Minimum', 'ritmo-revenue'), Cell::Money],
            [__('Share of the minimum', 'ritmo-revenue'), Cell::Fraction], [__('Sold since the last review', 'ritmo-revenue'), Cell::Money],
            [__('Occupancy sold', 'ritmo-revenue'), Cell::Fraction], [__('Market sold', 'ritmo-revenue'), Cell::Percent], [__('Market last year', 'ritmo-revenue'), Cell::Percent],
            [__('Reading', 'ritmo-revenue'), Cell::Text], [__('Average daily rate', 'ritmo-revenue'), Cell::Money], [__('Average sent price', 'ritmo-revenue'), Cell::Money],
            [__('Price change since the last review', 'ritmo-revenue'), Cell::Change],
        ], $rows);
    }

    /**
     * @param list<MonthForecast>  $forecast
     * @param list<PropertyTarget> $targets
     */
    public static function targets(array $forecast, array $targets): ReportTable
    {
        $byMonth = [];
        foreach ($targets as $t) {
            $byMonth[$t->month] = $t;
        }
        $rows = [];
        foreach ($forecast as $f) {
            $t = $byMonth[$f->month] ?? null;
            $rows[] = [
                ReportsText::month($f->month), $t?->minimum, $t?->realistic, $f->of(Scenario::Floor), $f->of(Scenario::Base), $f->of(Scenario::Goal), $f->onBooks,
                $t?->minimum === null ? null : $t->minimum - $f->onBooks, $f->missing === [] ? '' : __('last year\'s market not typed', 'ritmo-revenue'),
            ];
        }

        return new ReportTable('targets', __('Targets and forecast', 'ritmo-revenue'), [
            [__('Month', 'ritmo-revenue'), Cell::Text], [__('Minimum', 'ritmo-revenue'), Cell::Money], [__('Realistic', 'ritmo-revenue'), Cell::Money],
            [__('Forecast: floor', 'ritmo-revenue'), Cell::Money], [__('Forecast: base', 'ritmo-revenue'), Cell::Money], [__('Forecast: goal', 'ritmo-revenue'), Cell::Money],
            [__('On the books', 'ritmo-revenue'), Cell::Money], [__('Gap to the minimum', 'ritmo-revenue'), Cell::Money], [__('Note', 'ritmo-revenue'), Cell::Text],
        ], $rows);
    }

    /**
     * "Sold 32% vs the market's 18%: ahead" for the first month with a reading.
     */
    public static function summary(ReviewResult $r): string
    {
        foreach ($r->rows as $row) {
            if ($row->reading !== null && $row->marketSoldPercent !== null) {
                return sprintf(
                    /* translators: %1$s: month name, %2$s: own occupancy sold, %3$s: the market's, %4$s: the reading (ahead, behind...) */
                    __('%1$s: sold %2$s against the market\'s %3$s: %4$s.', 'ritmo-revenue'),
                    ReportsText::month($row->month),
                    Cell::Fraction->format($row->soldOccupancy, ''),
                    Cell::Percent->format($row->marketSoldPercent, ''),
                    ReportsText::reading($row->reading)
                );
            }
        }

        return __('Type the market\'s sold occupancy on the targets tab to compare each month with it.', 'ritmo-revenue');
    }
}
