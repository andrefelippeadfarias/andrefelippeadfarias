<?php

declare(strict_types=1);

namespace Ritmo\Domain\Market\Series;

use DateTimeImmutable;
use Ritmo\Domain\Pricing\Ladder\LadderStrategy;
use Ritmo\Domain\Pricing\Ladder\MarketRuler;
use Ritmo\Domain\Pricing\Ladder\RulerPoint;
use Ritmo\Domain\Support\Days;

/**
 * The nights an enabled sales ladder would price but cannot, because a series point one of its
 * rules cites has no value on the ruler (market spec 1.7, market_gap): the ladder leaves those
 * nights alone, so the screen and the alert name them instead of staying silent.
 */
final class MarketGaps
{
    private const HOLIDAY = 'holiday';

    private function __construct()
    {
    }

    /**
     * @param list<LadderStrategy>                                  $strategies the active strategies
     * @param list<array{night: DateTimeImmutable, type: string}> $nights     the nights to look at, with their kind
     * @param DateTimeImmutable                                     $today      today at the property (the last-minute floor reads near nights only)
     *
     * @return list<array{night: DateTimeImmutable, missing: list<RulerPoint>}> the gap nights, with the series points they miss (once each)
     */
    public static function find(array $strategies, MarketRuler $ruler, array $nights, DateTimeImmutable $today): array
    {
        $gaps = [];
        foreach ($nights as ['night' => $night, 'type' => $type]) {
            $missing = [];
            foreach ($strategies as $strategy) {
                if (!$strategy->enabled || !$strategy->covers($night)) {
                    continue;
                }
                foreach (self::points($strategy, $night, $type, $today) as $point) {
                    if ($ruler->night($night)?->valueAt($point->series, $point->percentile) === null) {
                        $missing[$point->series . ' ' . self::number($point->percentile)] = new RulerPoint($point->series, $point->percentile);
                    }
                }
            }
            if ($missing !== []) {
                $gaps[] = ['night' => $night, 'missing' => array_values($missing)];
            }
        }

        return $gaps;
    }

    /**
     * The series points a strategy reads on a night of this kind: the opening and the ceiling of
     * its rules for the kind, and its last-minute floor when that one reads a series on this
     * night (within its window, not on an excluded weekday, not on a holiday it skips).
     *
     * @return list<RulerPoint>
     */
    private static function points(LadderStrategy $strategy, DateTimeImmutable $night, string $type, DateTimeImmutable $today): array
    {
        $points = [];
        foreach ($strategy->rules as $rule) {
            if (in_array($type, $rule->nightTypes, true)) {
                array_push($points, ...array_filter([$rule->opening, $rule->ceiling]));
            }
        }
        $floor = $strategy->lastMinuteFloor;
        $daysOut = Days::diff($today, $night);
        $reads = $floor->enabled && $floor->series !== null && in_array($type, $floor->nightTypes, true)
            && $daysOut >= 0 && $daysOut <= $floor->windowDays
            && !in_array(Days::weekday($night), $floor->excludedWeekdays, true)
            && !($floor->skipHolidays && $type === self::HOLIDAY);
        if ($reads) {
            $points[] = new RulerPoint((string) $floor->series, $floor->percentile);
        }

        return $points;
    }

    private static function number(float $value): string
    {
        return rtrim(rtrim(sprintf('%.2F', $value), '0'), '.');
    }
}
