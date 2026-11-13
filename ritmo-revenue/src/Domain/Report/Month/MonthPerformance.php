<?php

declare(strict_types=1);

namespace Ritmo\Domain\Report\Month;

use DateTimeImmutable;
use Ritmo\Domain\Report\Booking\BookingLine;
use Ritmo\Domain\Report\Booking\BookingLines;
use Ritmo\Domain\Support\Days;
use Ritmo\Domain\Support\Num;

/**
 * Performance of a month: bookings made in it (from the 1st at 00:00 at the property to $asOf),
 * cancellations made in it, and its stays per property (realised before today, on the books from
 * today) against the owner's minimum.
 *
 * Reference: desempenho_mes.calcular. Divergence RP-X3: instants are compared as instants (a
 * cancellation at exactly $asOf counts; the reference compares the first 19 characters of text).
 */
final class MonthPerformance
{
    private function __construct()
    {
    }

    /**
     * @param list<BookingLine>           $lines      every counted line of the site (any month)
     * @param array<string, int>          $capacities property id => units of its active rooms, in display order
     * @param array<string, MonthTarget>  $targets    property id => the owner's targets of this month
     */
    public static function of(array $lines, string $month, DateTimeImmutable $asOf, array $capacities, array $targets, string $timezone): MonthReport
    {
        $period = Period::of($month, $timezone);
        $lines = array_values(array_filter($lines, static fn (BookingLine $l): bool => $l->isCounted()));
        $made = array_values(array_filter(
            $lines,
            static fn (BookingLine $l): bool => $l->createdAt !== null && $l->createdAt >= $period->startsAt && $l->createdAt <= $asOf
        ));
        $scopes = [...array_keys($capacities), MonthReport::SITE];
        $madeByScope = [];
        $cancelled = [];
        foreach ($scopes as $scope) {
            $propertyId = $scope === MonthReport::SITE ? null : (string) $scope;
            $madeByScope[$scope] = MadeInMonth::of($propertyId, BookingLines::ofProperty($made, $propertyId), $timezone);
            $cancelled[$scope] = self::cancellations($propertyId, BookingLines::ofProperty($lines, $propertyId), $period, $asOf);
        }
        $today = Days::localDate($asOf, $timezone);
        $stays = [];
        foreach ($capacities as $propertyId => $capacity) {
            $stays[$propertyId] = self::stays((string) $propertyId, $capacity, BookingLines::ofProperty($lines, (string) $propertyId), $period, $today, $targets[$propertyId] ?? new MonthTarget());
        }

        return new MonthReport($month, $asOf, $madeByScope, $cancelled, $stays);
    }

    /**
     * @param list<BookingLine> $lines
     */
    private static function cancellations(?string $propertyId, array $lines, Period $period, DateTimeImmutable $asOf): MonthCancellations
    {
        $count = $before = 0;
        $revenue = 0.0;
        foreach ($lines as $line) {
            if (!$line->isCancelled() || $line->cancelledAt === null || $line->cancelledAt < $period->startsAt || $line->cancelledAt > $asOf) {
                continue;
            }
            ++$count;
            $revenue += $line->revenue;
            $before += $line->createdAt !== null && $line->createdAt < $period->startsAt ? 1 : 0;
        }

        return new MonthCancellations($propertyId, $count, Num::roundHalfEven($revenue, 2), $before);
    }

    /**
     * @param list<BookingLine> $lines of the property
     */
    private static function stays(string $propertyId, int $capacity, array $lines, Period $period, DateTimeImmutable $today, MonthTarget $target): MonthStays
    {
        $realised = ['nights' => 0, 'revenue' => 0.0];
        $ahead = ['nights' => 0, 'revenue' => 0.0];
        foreach ($lines as $line) {
            if (!$line->isActive()) {
                continue;
            }
            for ($k = 0; $k < $line->nights; ++$k) {
                $night = Days::add($line->arrival, $k);
                if (!$period->contains($night)) {
                    continue;
                }
                if ($night < $today) {
                    $realised['nights'] += $line->units;
                    $realised['revenue'] += $line->revenuePerNight();
                } else {
                    $ahead['nights'] += $line->units;
                    $ahead['revenue'] += $line->revenuePerNight();
                }
            }
        }
        $daysPast = max(Days::diff($period->first, min($today, $period->end)), 0);

        return MonthStays::of($propertyId, $capacity, $period->days, $daysPast, $realised, $ahead, $target);
    }
}
