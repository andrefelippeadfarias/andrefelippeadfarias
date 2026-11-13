<?php

declare(strict_types=1);

namespace Ritmo\Admin\Reports;

use Ritmo\Admin\Components\NightDate;
use Ritmo\Domain\Report\Booking\BookingTotals;
use Ritmo\Domain\Report\Weekly\ShareRow;
use Ritmo\Domain\Report\Weekly\WeeklyReport;
use Ritmo\Domain\Support\Days;

/**
 * The five tables of the weekly panel (spec 06, 2.6), for one property.
 */
final class WeeklyTables
{
    private function __construct()
    {
    }

    public static function made(WeeklyReport $r): ReportTable
    {
        $rows = [];
        foreach ($r->made as $week) {
            $rows[] = [Days::key($week->monday), $week->complete ? __('yes', 'ritmo-revenue') : __('not yet', 'ritmo-revenue'), ...self::totals($week->totals), $week->cancellations, $week->cancelledRevenue];
        }

        return new ReportTable('weekly-made', __('Bookings made per week', 'ritmo-revenue'), [
            [__('Week starting', 'ritmo-revenue'), Cell::Date], [__('Week complete', 'ritmo-revenue'), Cell::Text], ...self::totalsColumns(),
            [__('Cancellations made', 'ritmo-revenue'), Cell::Count], [__('Revenue cancelled', 'ritmo-revenue'), Cell::Money],
        ], $rows);
    }

    public static function stays(WeeklyReport $r): ReportTable
    {
        $rows = [];
        foreach ($r->stays as $week) {
            $rows[] = [Days::key($week->monday), ReportsText::moment($week->moment), $week->roomNights, $week->capacity, $week->occupancy, $week->revenue, $week->adr, $week->revpar];
        }

        return new ReportTable('weekly-stays', __('Stays per week', 'ritmo-revenue'), [
            [__('Week starting', 'ritmo-revenue'), Cell::Date], [__('When', 'ritmo-revenue'), Cell::Text], [__('Room nights sold', 'ritmo-revenue'), Cell::Count],
            [__('Room nights for sale', 'ritmo-revenue'), Cell::Count], [__('Occupancy', 'ritmo-revenue'), Cell::Fraction], [__('Revenue', 'ritmo-revenue'), Cell::Money],
            [__('Average daily rate', 'ritmo-revenue'), Cell::Money], [__('Revenue per available room night', 'ritmo-revenue'), Cell::Money],
        ], $rows);
    }

    public static function goals(WeeklyReport $r): ReportTable
    {
        $rows = [];
        foreach ($r->goals as $month => $stays) {
            foreach ($stays as $s) {
                $rows[] = [ReportsText::month((string) $month), $s->realisedRevenue, $s->aheadRevenue, $s->revenue, $s->minimum, $s->gapToMinimum, $s->occupancy, $s->adr, $s->freeNights, $s->adrNeeded, $s->achieved(4)];
            }
        }

        return new ReportTable('weekly-goals', __('This month and the next two against the minimum', 'ritmo-revenue'), [
            [__('Month', 'ritmo-revenue'), Cell::Text], [__('Realised', 'ritmo-revenue'), Cell::Money], [__('On the books ahead', 'ritmo-revenue'), Cell::Money],
            [__('Total', 'ritmo-revenue'), Cell::Money], [__('Minimum', 'ritmo-revenue'), Cell::Money], [__('Still missing', 'ritmo-revenue'), Cell::Money],
            [__('Occupancy', 'ritmo-revenue'), Cell::Fraction], [__('Average daily rate', 'ritmo-revenue'), Cell::Money], [__('Room nights still free', 'ritmo-revenue'), Cell::Count],
            [__('Daily rate needed on them', 'ritmo-revenue'), Cell::Money], [__('Share of the minimum', 'ritmo-revenue'), Cell::Fraction],
        ], $rows);
    }

    /**
     * @param array<string, string> $roomNames
     */
    public static function shares(WeeklyReport $r, bool $channels, array $roomNames): ReportTable
    {
        $rows = [];
        $sum = [0, 0, 0, 0, 0.0];
        foreach ($channels ? $r->channels : $r->rooms as $row) {
            $rows[] = [$channels ? ReportsText::channel($row->key) : ($roomNames[$row->key] ?? $row->key), ...self::totals($row->totals), $row->share];
            $sum = self::add($sum, $row);
        }
        $first = $channels ? __('Channel', 'ritmo-revenue') : __('Room', 'ritmo-revenue');

        return new ReportTable($channels ? 'weekly-channels' : 'weekly-rooms', $channels ? __('Bookings of the last 28 days by channel', 'ritmo-revenue') : __('Bookings of the last 28 days by room', 'ritmo-revenue'), [
            [$first, Cell::Text], ...self::totalsColumns(), [__('Share of the revenue', 'ritmo-revenue'), Cell::Fraction],
        ], $rows, $rows === [] ? null : [__('Total', 'ritmo-revenue'), $sum[0], $sum[1], $sum[2], $sum[3], $sum[4], null, null, null, null]);
    }

    /**
     * @return list<array{0: string, 1: Cell}>
     */
    private static function totalsColumns(): array
    {
        return [
            [__('Bookings', 'ritmo-revenue'), Cell::Count], [__('Cancelled', 'ritmo-revenue'), Cell::Count], [__('Active', 'ritmo-revenue'), Cell::Count],
            [__('Room nights', 'ritmo-revenue'), Cell::Count], [__('Revenue', 'ritmo-revenue'), Cell::Money], [__('Average daily rate', 'ritmo-revenue'), Cell::Money],
            [__('Mean lead time (days)', 'ritmo-revenue'), Cell::Count], [__('Mean stay (nights)', 'ritmo-revenue'), Cell::Count],
        ];
    }

    /**
     * @return list<int|float|null>
     */
    private static function totals(BookingTotals $t): array
    {
        return [$t->bookings, $t->cancelled, $t->active, $t->roomNights, $t->revenue, $t->adr, $t->meanLeadDays, $t->meanStayNights];
    }

    /**
     * @param array{0: int, 1: int, 2: int, 3: int, 4: float} $sum
     *
     * @return array{0: int, 1: int, 2: int, 3: int, 4: float}
     */
    private static function add(array $sum, ShareRow $row): array
    {
        return [$sum[0] + $row->totals->bookings, $sum[1] + $row->totals->cancelled, $sum[2] + $row->totals->active, $sum[3] + $row->totals->roomNights, $sum[4] + $row->totals->revenue];
    }

    /**
     * "Week of 9 Nov: 14 bookings made for R$ 9,800; stays of the week 71% occupied at an average
     * daily rate of R$ 480" from the rows of the current week (the same numbers as the tables).
     */
    public static function summary(WeeklyReport $r, string $currency): string
    {
        $made = $r->made === [] ? null : $r->made[array_key_last($r->made)];
        $stay = null;
        foreach ($r->stays as $week) {
            if ($made !== null && $week->monday == $made->monday) {
                $stay = $week;
            }
        }
        if ($made === null || $stay === null) {
            return __('No week to show yet.', 'ritmo-revenue');
        }

        return sprintf(
            /* translators: %1$s: first day of the week, %2$d: bookings made, %3$s: their revenue, %4$s: occupancy, %5$s: average daily rate */
            _n('Week of %1$s: %2$d booking made for %3$s; stays of the week %4$s occupied at an average daily rate of %5$s.', 'Week of %1$s: %2$d bookings made for %3$s; stays of the week %4$s occupied at an average daily rate of %5$s.', $made->totals->bookings, 'ritmo-revenue'),
            NightDate::short($made->monday),
            $made->totals->bookings,
            Cell::Money->format($made->totals->revenue, $currency),
            Cell::Fraction->format($stay->occupancy, $currency),
            Cell::Money->format($stay->adr, $currency)
        );
    }
}
