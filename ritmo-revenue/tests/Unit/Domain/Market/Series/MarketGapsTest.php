<?php

declare(strict_types=1);

namespace Ritmo\Tests\Unit\Domain\Market\Series;

use PHPUnit\Framework\TestCase;
use Ritmo\Domain\Market\Series\MarketGaps;
use Ritmo\Domain\Pricing\Ladder\LadderConfig;
use Ritmo\Domain\Pricing\Ladder\LadderStrategy;
use Ritmo\Domain\Pricing\Ladder\MarketRuler;
use Ritmo\Domain\Pricing\Ladder\RulerNight;
use Ritmo\Domain\Pricing\Ladder\RulerPoint;
use Ritmo\Domain\Support\Days;

/**
 * The nights an enabled sales ladder would price but cannot (market spec 1.7, market_gap): a
 * rule of the night's kind cites a series point the ruler has no value for. Disabled strategies,
 * nights outside a strategy's period and rules of other kinds of night do not count; the
 * last-minute floor counts only inside its window.
 */
final class MarketGapsTest extends TestCase
{
    public function testANightWithoutTheCitedPointIsAGapAndNamesThePoint(): void
    {
        $strategies = self::strategies(['rules' => [['night_types' => ['weekend'], 'rooms' => ['A'], 'opening' => ['series' => 'comparable', 'percentile' => 50], 'ceiling' => ['series' => 'comparable', 'percentile' => 90]]]]);
        $ruler = new MarketRuler([
            new RulerNight(Days::date('2026-11-20'), 'weekend', ['comparable' => ['50' => 500.0, '90' => 700.0]]),
            new RulerNight(Days::date('2026-11-21'), 'weekend', ['comparable' => ['50' => 520.0]]),
        ]);

        $gaps = MarketGaps::find($strategies, $ruler, self::nights(['2026-11-19' => 'weekday', '2026-11-20' => 'weekend', '2026-11-21' => 'weekend', '2026-11-27' => 'weekend']), Days::date('2026-11-13'));

        self::assertSame([['2026-11-21', ['comparable 90']], ['2026-11-27', ['comparable 50', 'comparable 90']]], self::plain($gaps), 'a weekday night is not priced by a weekend rule');
    }

    public function testDisabledStrategiesAndNightsOutsideThePeriodAreNoGaps(): void
    {
        $rule = ['night_types' => ['weekday'], 'rooms' => ['A'], 'opening' => ['series' => 'cheapest', 'percentile' => 50]];
        $nights = self::nights(['2026-11-16' => 'weekday', '2026-12-16' => 'weekday']);

        self::assertSame([], MarketGaps::find(self::strategies(['rules' => [$rule]], enabled: false), new MarketRuler(), $nights, Days::date('2026-11-13')));
        self::assertSame(
            [['2026-11-16', ['cheapest 50']]],
            self::plain(MarketGaps::find(self::strategies(['valid_to' => '2026-11-30', 'rules' => [$rule]]), new MarketRuler(), $nights, Days::date('2026-11-13')))
        );
    }

    public function testTheLastMinuteFloorCountsOnlyInsideItsWindowAndNotOnSkippedNights(): void
    {
        $strategies = self::strategies(['rules' => [], 'last_minute_floor' => ['enabled' => true, 'night_types' => ['weekday', 'holiday'], 'excluded_weekdays' => [2], 'skip_holidays' => true, 'window_days' => 3, 'series' => 'comparable', 'percentile' => 40, 'rooms' => ['A' => 1.0]]]);
        $nights = self::nights(['2026-11-16' => 'weekday', '2026-11-17' => 'holiday', '2026-11-18' => 'weekday', '2026-11-23' => 'weekday']);

        $gaps = MarketGaps::find($strategies, new MarketRuler(), $nights, Days::date('2026-11-15'));

        self::assertSame([['2026-11-16', ['comparable 40']]], self::plain($gaps), 'the 17th is a holiday it skips, the 18th a Wednesday it excludes, the 23rd beyond 3 days');
    }

    /**
     * @param array<string, mixed> $strategy
     *
     * @return list<LadderStrategy>
     */
    private static function strategies(array $strategy, bool $enabled = true): array
    {
        return LadderConfig::fromArray(['enabled' => true, 'strategies' => [['id' => 'one', 'enabled' => $enabled] + $strategy]])->strategies;
    }

    /**
     * @param array<string, string> $types night => kind
     *
     * @return list<array{night: \DateTimeImmutable, type: string}>
     */
    private static function nights(array $types): array
    {
        return array_map(static fn (string $night, string $type): array => ['night' => Days::date($night), 'type' => $type], array_keys($types), $types);
    }

    /**
     * @param list<array{night: \DateTimeImmutable, missing: list<RulerPoint>}> $gaps
     *
     * @return list<array{0: string, 1: list<string>}>
     */
    private static function plain(array $gaps): array
    {
        return array_map(static fn (array $gap): array => [Days::key($gap['night']), array_map(static fn (RulerPoint $p): string => $p->series . ' ' . $p->percentile, $gap['missing'])], $gaps);
    }
}
