<?php

declare(strict_types=1);

namespace Ritmo\Admin\Reports;

use Ritmo\Domain\Report\Booking\BookingTotals;
use Ritmo\Domain\Report\Month\MonthReport;

/**
 * The month report (spec 06, 2.3) as one table with a section and a dimension column: bookings
 * made in the month (total, by channel, room, week of the month and month of the stay), the
 * cancellations made in it and the stays of the month against the minimum.
 */
final class MonthTables
{
    private function __construct()
    {
    }

    /**
     * @param array<string, string> $roomNames
     */
    public static function of(MonthReport $r, string $propertyId, array $roomNames): ReportTable
    {
        $made = $r->made[$propertyId] ?? $r->made[MonthReport::SITE];
        $rows = [self::made(__('made', 'ritmo-revenue'), __('total', 'ritmo-revenue'), __('All bookings', 'ritmo-revenue'), $made->overall)];
        foreach ([[__('channel', 'ritmo-revenue'), $made->byChannel, static fn (string $k): string => ReportsText::channel($k)],
            [__('room', 'ritmo-revenue'), $made->byRoom, static fn (string $k): string => $roomNames[$k] ?? $k],
            /* translators: %s: week number in the month (1 to 5) */
            [__('week of the month', 'ritmo-revenue'), $made->byWeek, static fn (string $k): string => sprintf(__('Week %s', 'ritmo-revenue'), $k)],
            [__('month of the stay', 'ritmo-revenue'), $made->byStayMonth, static fn (string $k): string => ReportsText::month($k)]] as [$dimension, $groups, $name]) {
            foreach ($groups as $key => $totals) {
                $rows[] = self::made(__('made', 'ritmo-revenue'), $dimension, $name((string) $key), $totals);
            }
        }
        $cancelled = $r->cancellations[$propertyId] ?? $r->cancellations[MonthReport::SITE];
        $rows[] = [__('cancellations', 'ritmo-revenue'), __('total', 'ritmo-revenue'), __('Cancelled in the month', 'ritmo-revenue'), $cancelled->count, null, null, null, $cancelled->revenue, null, null, null, null, null, null];
        $s = $r->stays[$propertyId] ?? null;
        if ($s !== null) {
            $rows[] = [__('stays', 'ritmo-revenue'), __('realised', 'ritmo-revenue'), __('Nights before today', 'ritmo-revenue'), null, null, $s->realisedNights, null, $s->realisedRevenue, $s->realisedAdr, $s->realisedOccupancy, null, null, null, null];
            $rows[] = [__('stays', 'ritmo-revenue'), __('on the books', 'ritmo-revenue'), __('Today to the month\'s end', 'ritmo-revenue'), null, null, $s->aheadNights, null, $s->aheadRevenue, null, $s->aheadOccupancy, null, null, $s->freeNights, null];
            $rows[] = [__('stays', 'ritmo-revenue'), __('total', 'ritmo-revenue'), __('The whole month', 'ritmo-revenue'), null, null, $s->nights, null, $s->revenue, $s->adr, $s->occupancy, $s->minimum, $s->gapToMinimum, null, $s->adrNeeded];
        }

        return new ReportTable('month', __('The month', 'ritmo-revenue'), [
            [__('Section', 'ritmo-revenue'), Cell::Text], [__('Dimension', 'ritmo-revenue'), Cell::Text], [__('Row', 'ritmo-revenue'), Cell::Text],
            [__('Bookings', 'ritmo-revenue'), Cell::Count], [__('Cancelled', 'ritmo-revenue'), Cell::Count], [__('Room nights', 'ritmo-revenue'), Cell::Count],
            [__('Mean lead time (days)', 'ritmo-revenue'), Cell::Count], [__('Revenue', 'ritmo-revenue'), Cell::Money], [__('Average daily rate', 'ritmo-revenue'), Cell::Money],
            [__('Occupancy', 'ritmo-revenue'), Cell::Fraction], [__('Minimum', 'ritmo-revenue'), Cell::Money], [__('Still missing', 'ritmo-revenue'), Cell::Money],
            [__('Room nights still free', 'ritmo-revenue'), Cell::Count], [__('Daily rate needed on them', 'ritmo-revenue'), Cell::Money],
        ], $rows);
    }

    /**
     * @return list<string|int|float|null>
     */
    private static function made(string $section, string $dimension, string $name, BookingTotals $t): array
    {
        return [$section, $dimension, $name, $t->bookings, $t->cancelled, $t->roomNights, $t->meanLeadDays, $t->revenue, $t->adr, null, null, null, null, null];
    }

    /**
     * "Made this month: 26 bookings for R$ 53,045 (3 cancelled)."
     */
    public static function summary(MonthReport $r, string $propertyId, string $currency): string
    {
        $t = ($r->made[$propertyId] ?? $r->made[MonthReport::SITE])->overall;

        return sprintf(
            /* translators: %1$s: month name, %2$d: bookings, %3$s: revenue, %4$d: cancelled */
            _n('%1$s: %2$d booking made for %3$s (%4$d cancelled).', '%1$s: %2$d bookings made for %3$s (%4$d cancelled).', $t->bookings, 'ritmo-revenue'),
            ReportsText::month($r->month),
            $t->bookings,
            Cell::Money->format($t->revenue, $currency),
            $t->cancelled
        );
    }
}
