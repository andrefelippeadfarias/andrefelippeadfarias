<?php

declare(strict_types=1);

namespace Ritmo\Domain\Report\Review;

use DateTimeImmutable;
use Ritmo\Domain\Report\Booking\BookingLine;
use Ritmo\Domain\Report\Month\MonthPerformance;
use Ritmo\Domain\Report\Month\MonthStays;
use Ritmo\Domain\Report\Month\MonthTarget;
use Ritmo\Domain\Report\Month\Period;
use Ritmo\Domain\Support\Num;

/**
 * The 12-month review: per month (the current and the next 12) and property, the sold revenue,
 * occupancy and daily rate of the stays on the books, the owner's targets and how much of the
 * minimum is sold, the pickup since the previous snapshot, the market's sold occupancy and the
 * reading against it, and the average sent price with its change since the previous snapshot.
 *
 * Reference: revisao_12m.rodar (divergences RP-X5 market occupancy typed or imported, RP-X6 the
 * rooms of the configured tags).
 */
final class TwelveMonthReview
{
    public const MONTHS = 13;

    private function __construct()
    {
    }

    /**
     * @param list<BookingLine>                         $lines
     * @param array<string, int>                        $capacities property id => units, in display order
     * @param array<string, array<string, MonthTarget>> $targets    month => property id => targets
     * @param array<string, MarketMonth>                $market     month => market occupancy
     * @param array<string, array<string, float>>       $prices     property id => month => average sent price
     * @param ReviewSnapshot|null                       $previous   the latest snapshot (ignored when not before today)
     */
    public static function of(
        array $lines,
        DateTimeImmutable $today,
        DateTimeImmutable $asOf,
        array $capacities,
        array $targets,
        array $market,
        array $prices,
        ?ReviewSnapshot $previous,
        ReviewConfig $config,
        string $timezone,
    ): ReviewResult {
        $previous = $previous !== null && $previous->day < $today ? $previous : null;
        $current = Period::keyOf($today);
        $rows = [];
        foreach (Period::following($today, self::MONTHS) as $month) {
            $report = MonthPerformance::of($lines, $month, $asOf, $capacities, $targets[$month] ?? [], $timezone);
            foreach ($report->stays as $propertyId => $stays) {
                $rows[] = self::row($month, (string) $propertyId, $stays, $targets[$month][$propertyId] ?? new MonthTarget(), $market[$month] ?? null, $prices[$propertyId][$month] ?? null, $previous, $config, $month === $current);
            }
        }

        return new ReviewResult($today, $asOf, $previous?->day, $rows);
    }

    private static function row(
        string $month,
        string $propertyId,
        MonthStays $stays,
        MonthTarget $target,
        ?MarketMonth $market,
        ?float $price,
        ?ReviewSnapshot $previous,
        ReviewConfig $config,
        bool $isCurrent,
    ): ReviewRow {
        $before = $previous?->row($month, $propertyId);
        $reading = null;
        if ($market?->soldPercent !== null && !$isCurrent && $stays->occupancy !== null) {
            $reading = $config->reading($stays->occupancy, $market->soldPercent);
        }
        $beforePrice = $before['price'] ?? null;
        $changes = $beforePrice !== null && $beforePrice != 0.0 && $price !== null && $price != 0.0;

        return new ReviewRow(
            $month,
            $propertyId,
            $stays->revenue,
            $stays->occupancy,
            $stays->adr,
            $target->minimum,
            $target->realistic,
            $stays->achieved(3),
            $before !== null ? Num::roundHalfEven($stays->revenue - $before['sold'], 2) : null,
            $market?->soldPercent,
            $market?->lastYearPercent,
            $reading,
            $price,
            $changes ? Num::roundHalfEven($price / $beforePrice - 1, 3) : null,
        );
    }
}
