<?php

declare(strict_types=1);

namespace Ritmo\Tests\Unit\Domain\Market;

use PHPUnit\Framework\TestCase;
use Ritmo\Domain\Market\AnchorLookup;
use Ritmo\Domain\Model\AnchorOrigin;
use Ritmo\Domain\Model\MarketAnchor;
use Ritmo\Domain\Support\Days;
use Ritmo\Tests\Unit\Domain\EngineSample;

final class AnchorLookupTest extends TestCase
{
    /**
     * @param array{float, float, float, float, float} $points
     */
    private static function anchor(string $night, array $points, string $capturedAt = '2027-03-01'): MarketAnchor
    {
        return new MarketAnchor(Days::date($night), $points[0], $points[1], $points[2], $points[3], $points[4], 9, Days::date($capturedAt), AnchorOrigin::Measured);
    }

    private static function lookup(): AnchorLookup
    {
        return new AnchorLookup(EngineSample::rules(), [
            '2027-03-08' => self::anchor('2027-03-08', [100.0, 200.0, 300.0, 400.0, 500.0]),
            '2027-03-09' => self::anchor('2027-03-09', [120.0, 220.0, 320.0, 420.0, 520.0], '2027-03-02'),
            '2027-03-12' => self::anchor('2027-03-12', [300.0, 400.0, 500.0, 600.0, 700.0]),
            '2027-05-10' => self::anchor('2027-05-10', [150.0, 250.0, 350.0, 450.0, 550.0]),
        ]);
    }

    public function testANightWithItsOwnAnchorGetsIt(): void
    {
        $anchor = self::lookup()->forNight(Days::date('2027-03-09'));

        self::assertSame(220.0, $anchor?->p50);
        self::assertSame(9, $anchor->n);
        self::assertSame(AnchorOrigin::Measured, $anchor->origin);
    }

    public function testANightWithoutOneFallsBackToItsNightTypeInItsMonth(): void
    {
        $anchor = self::lookup()->forNight(Days::date('2027-03-11'));

        self::assertNotNull($anchor);
        self::assertSame([110.0, 210.0, 310.0, 410.0, 510.0], [$anchor->p25, $anchor->p50, $anchor->p75, $anchor->p90, $anchor->max], 'the median of the two weekday nights of March');
        self::assertSame('2027-03-11', Days::key($anchor->date), 'dated as the night asked');
        self::assertSame(0, $anchor->n);
        self::assertSame(AnchorOrigin::Fallback, $anchor->origin);
        self::assertSame('2027-03-02', Days::key($anchor->capturedAt));

        self::assertSame(500.0, self::lookup()->forNight(Days::date('2027-03-13'))?->p75, 'a Saturday uses the weekend nights');
    }

    public function testThenToTheClosestMonthOfItsNightType(): void
    {
        $lookup = self::lookup();

        self::assertSame(210.0, $lookup->forNight(Days::date('2027-04-14'))?->p50, '30 days from 15 March, 31 from 15 May');
        self::assertSame(250.0, $lookup->forNight(Days::date('2027-04-15'))?->p50, '31 days from 15 March, 30 from 15 May');
        self::assertSame(250.0, $lookup->forNight(Days::date('2027-08-02'))?->p50, 'far after the last month');
        self::assertSame(400.0, $lookup->forNight(Days::date('2027-06-04'))?->p50, 'a Friday: the only weekend month is March');
    }

    public function testTheEarlierMonthWinsATie(): void
    {
        $lookup = new AnchorLookup(EngineSample::rules(), [
            '2027-02-05' => self::anchor('2027-02-05', [3.0, 4.0, 5.0, 6.0, 7.0]),
            '2026-12-04' => self::anchor('2026-12-04', [1.0, 2.0, 3.0, 4.0, 5.0]),
        ]);

        self::assertSame(2.0, $lookup->forNight(Days::date('2027-01-15'))?->p50, 'a Friday 31 days from both 15 December and 15 February');
    }

    public function testANightTypeWithoutAnyAnchorHasNone(): void
    {
        self::assertNull(self::lookup()->forNight(Days::date('2027-03-18')), 'no holiday night was ever measured');
        self::assertNull((new AnchorLookup(EngineSample::rules(), []))->forNight(Days::date('2027-03-08')));
    }

    public function testValueAtInterpolatesBetweenTheKnownPoints(): void
    {
        $anchor = EngineSample::anchor('2027-03-10');

        foreach ([[0, 0.0], [12.5, 200.0], [25, 400.0], [37.5, 500.0], [50, 600.0], [75, 800.0], [90, 1000.0], [95, 1100.0], [100, 1200.0], [110, 1320.0]] as [$percentile, $expected]) {
            self::assertEqualsWithDelta($expected, AnchorLookup::valueAt($anchor, (float) $percentile), 1e-9, "p$percentile");
        }
    }

    public function testPercentileOfIsTheInverseWithOneDecimal(): void
    {
        $anchor = EngineSample::anchor('2027-03-10');

        foreach ([[0.0, 0.0], [200.0, 12.5], [400.0, 25.0], [500.0, 37.5], [633.0, 54.1], [1000.0, 90.0], [1100.0, 95.0], [1200.0, 100.0], [1320.0, 101.0]] as [$price, $expected]) {
            self::assertSame($expected, AnchorLookup::percentileOf($anchor, $price), "price $price");
        }
    }

    public function testPercentileOfOnFlatAndEmptyAnchors(): void
    {
        $flat = self::anchor('2027-03-10', [300.0, 450.0, 450.0, 450.0, 900.0]);
        self::assertSame(50.0, AnchorLookup::percentileOf($flat, 450.0), 'the first point that reaches the price');
        self::assertSame(90.0, AnchorLookup::percentileOf($flat, 451.0));

        $empty = self::anchor('2027-03-10', [0.0, 0.0, 0.0, 0.0, 0.0]);
        self::assertSame(0.0, AnchorLookup::percentileOf($empty, 0.0));
        self::assertSame(100.0, AnchorLookup::percentileOf($empty, 10.0));
        self::assertSame(0.0, AnchorLookup::valueAt($empty, 80.0));
    }
}
