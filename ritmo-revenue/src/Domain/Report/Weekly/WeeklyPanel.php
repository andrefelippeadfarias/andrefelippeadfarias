<?php

declare(strict_types=1);

namespace Ritmo\Domain\Report\Weekly;

use Closure;
use DateTimeImmutable;
use Ritmo\Domain\Report\Booking\BookingLine;
use Ritmo\Domain\Report\Booking\BookingLines;
use Ritmo\Domain\Report\Booking\BookingTotals;
use Ritmo\Domain\Report\Booking\Grouping;
use Ritmo\Domain\Report\Month\MonthPerformance;
use Ritmo\Domain\Report\Month\MonthTarget;
use Ritmo\Domain\Report\Month\Period;
use Ritmo\Domain\Support\Days;
use Ritmo\Domain\Support\Num;

/**
 * The weekly panel: bookings made per week since the start week, stays per stay week (8 back,
 * 8 ahead), the current month and the next two against the owner's minimum, and the bookings of
 * the last 28 days per channel and per room with their share of the property's revenue.
 *
 * Reference: painel_semanal.montar (divergences RP-X2 property timezone, RP-X8 configurable start;
 * without a start, the 26 weeks before the current week).
 */
final class WeeklyPanel
{
    public const WEEKS_AROUND = 8;
    public const DEFAULT_WEEKS_BACK = 26;
    public const RECENT_DAYS = 28;
    public const GOAL_MONTHS = 3;

    private function __construct()
    {
    }

    /**
     * @param list<BookingLine>                         $lines
     * @param array<string, int>                        $capacities property id => units, in display order
     * @param array<string, array<string, MonthTarget>> $targets    month => property id => targets
     * @param DateTimeImmutable|null                    $start      first week (its Monday is used); null = 26 weeks back
     */
    public static function of(array $lines, DateTimeImmutable $asOf, array $capacities, array $targets, string $timezone, ?DateTimeImmutable $start): WeeklyReport
    {
        $lines = array_values(array_filter($lines, static fn (BookingLine $l): bool => $l->isCounted()));
        $today = Days::localDate($asOf, $timezone);
        $monday = self::monday($today);
        $start = $start === null ? Days::add($monday, -7 * self::DEFAULT_WEEKS_BACK) : self::monday($start);
        $local = static fn (?DateTimeImmutable $instant): ?DateTimeImmutable => $instant === null ? null : Days::localDate($instant, $timezone);
        $goals = [];
        foreach (Period::following($today, self::GOAL_MONTHS) as $month) {
            $goals[$month] = MonthPerformance::of($lines, $month, $asOf, $capacities, $targets[$month] ?? [], $timezone)->stays;
        }
        [$channels, $rooms] = self::recent($lines, $today, $capacities, $local);

        return new WeeklyReport(
            $today,
            $start,
            self::made($lines, $start, $monday, $today, $capacities, $local),
            self::stays($lines, $start, $monday, $today, $capacities),
            $goals,
            $channels,
            $rooms,
        );
    }

    public static function monday(DateTimeImmutable $day): DateTimeImmutable
    {
        return Days::add(Days::normalize($day), -Days::weekday($day));
    }

    /**
     * @param list<BookingLine>                                   $lines
     * @param array<string, int>                                  $capacities
     * @param Closure(?DateTimeImmutable): ?DateTimeImmutable     $local
     *
     * @return list<MadeWeek>
     */
    private static function made(array $lines, DateTimeImmutable $start, DateTimeImmutable $monday, DateTimeImmutable $today, array $capacities, Closure $local): array
    {
        $out = [];
        for ($week = $start; $week <= $monday; $week = Days::add($week, 7)) {
            $end = Days::add($week, 7);
            $within = static fn (?DateTimeImmutable $day): bool => $day !== null && $day >= $week && $day < $end;
            foreach (array_keys($capacities) as $propertyId) {
                $ofProperty = BookingLines::ofProperty($lines, (string) $propertyId);
                $made = array_values(array_filter($ofProperty, static fn (BookingLine $l): bool => $within($local($l->createdAt))));
                $cancelled = array_values(array_filter($ofProperty, static fn (BookingLine $l): bool => $l->isCancelled() && $within($local($l->cancelledAt))));
                $out[] = new MadeWeek($week, (string) $propertyId, $end <= $today, BookingTotals::of($made), count($cancelled), Num::roundHalfEven(array_sum(array_map(static fn (BookingLine $l): float => $l->revenue, $cancelled)), 2));
            }
        }

        return $out;
    }

    /**
     * @param list<BookingLine>  $lines
     * @param array<string, int> $capacities
     *
     * @return list<StayWeek>
     */
    private static function stays(array $lines, DateTimeImmutable $start, DateTimeImmutable $monday, DateTimeImmutable $today, array $capacities): array
    {
        $sums = [];
        foreach ($lines as $line) {
            if (!$line->isActive() || $line->propertyId === null) {
                continue;
            }
            for ($k = 0; $k < $line->nights; ++$k) {
                $key = Days::key(self::monday(Days::add($line->arrival, $k))) . '|' . $line->propertyId;
                $sums[$key]['nights'] = ($sums[$key]['nights'] ?? 0) + $line->units;
                $sums[$key]['revenue'] = ($sums[$key]['revenue'] ?? 0.0) + $line->revenuePerNight();
            }
        }
        $out = [];
        for ($i = -self::WEEKS_AROUND; $i <= self::WEEKS_AROUND; ++$i) {
            $week = Days::add($monday, 7 * $i);
            if ($week < $start) {
                continue;
            }
            $moment = Days::add($week, 7) <= $today ? Moment::Past : ($week == $monday ? Moment::Current : Moment::Future);
            foreach ($capacities as $propertyId => $units) {
                $sum = $sums[Days::key($week) . '|' . $propertyId] ?? ['nights' => 0, 'revenue' => 0.0];
                $capacity = $units * 7;
                $out[] = new StayWeek(
                    $week,
                    (string) $propertyId,
                    $moment,
                    $sum['nights'],
                    $capacity,
                    $capacity > 0 ? Num::roundHalfEven($sum['nights'] / $capacity, 4) : null,
                    Num::roundHalfEven($sum['revenue'], 2),
                    $sum['nights'] > 0 ? Num::roundHalfEven($sum['revenue'] / $sum['nights'], 2) : null,
                    $capacity > 0 ? Num::roundHalfEven($sum['revenue'] / $capacity, 2) : null,
                );
            }
        }

        return $out;
    }

    /**
     * @param list<BookingLine>                               $lines
     * @param array<string, int>                              $capacities
     * @param Closure(?DateTimeImmutable): ?DateTimeImmutable $local
     *
     * @return array{0: list<ShareRow>, 1: list<ShareRow>}
     */
    private static function recent(array $lines, DateTimeImmutable $today, array $capacities, Closure $local): array
    {
        $first = Days::add($today, -self::RECENT_DAYS);
        $recent = array_values(array_filter($lines, static function (BookingLine $l) use ($first, $today, $local): bool {
            $day = $local($l->createdAt);

            return $day !== null && $day >= $first && $day <= $today;
        }));
        $channels = $rooms = [];
        foreach (array_keys($capacities) as $propertyId) {
            $ofProperty = BookingLines::ofProperty($recent, (string) $propertyId);
            $total = array_sum(array_map(static fn (BookingLine $l): float => $l->isActive() ? $l->revenue : 0.0, $ofProperty));
            $total = $total == 0.0 ? 1.0 : $total;
            $channels = [...$channels, ...self::shares((string) $propertyId, Grouping::totals($ofProperty, static fn (BookingLine $l): string => $l->channel), $total)];
            $rooms = [...$rooms, ...self::shares((string) $propertyId, Grouping::totals($ofProperty, static fn (BookingLine $l): string => $l->roomCode ?? ''), $total)];
        }

        return [$channels, $rooms];
    }

    /**
     * @param array<int|string, BookingTotals> $groups
     *
     * @return list<ShareRow>
     */
    private static function shares(string $propertyId, array $groups, float $total): array
    {
        $out = [];
        foreach ($groups as $key => $totals) {
            $out[] = new ShareRow($propertyId, (string) $key, $totals, Num::roundHalfEven($totals->revenue / $total, 4));
        }

        return $out;
    }
}
