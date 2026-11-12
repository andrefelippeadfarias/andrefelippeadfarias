<?php

declare(strict_types=1);

namespace Ritmo\Tests\Unit\Domain\Market;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Ritmo\Domain\Market\AnchorBuilder;
use Ritmo\Domain\Market\PriceSample;
use Ritmo\Domain\Model\AnchorOrigin;
use Ritmo\Domain\Model\MarketAnchor;
use Ritmo\Domain\Support\Days;
use Ritmo\Tests\Unit\Domain\EngineSample;

final class AnchorBuilderTest extends TestCase
{
    private static function sample(string $night, float $price, string $capturedAt = '2027-03-01', string $source = ''): PriceSample
    {
        return new PriceSample(Days::date($night), $price, Days::date($capturedAt), $source);
    }

    /**
     * @param array{float, float, float, float, float} $points
     */
    private static function anchor(string $night, array $points, int $n = 10, ?string $capturedAt = '2027-03-01', AnchorOrigin $origin = AnchorOrigin::Measured): MarketAnchor
    {
        return new MarketAnchor(Days::date($night), $points[0], $points[1], $points[2], $points[3], $points[4], $n, $capturedAt === null ? null : Days::date($capturedAt), $origin);
    }

    public function testBuildsThePercentilesOfTheSamplesOfANight(): void
    {
        $builder = new AnchorBuilder(EngineSample::rules());
        $samples = array_map(static fn (float $price): PriceSample => self::sample('2027-03-10', $price), [500.0, 700.0, 900.0, 1100.0, 600.0]);
        $samples[] = self::sample('2027-03-11', 5000.0);

        $anchor = $builder->fromSamples(Days::date('2027-03-10'), $samples);

        self::assertNotNull($anchor);
        self::assertSame([600.0, 700.0, 900.0, 1020.0, 1100.0], [$anchor->p25, $anchor->p50, $anchor->p75, $anchor->p90, $anchor->max]);
        self::assertSame(5, $anchor->n, 'the sample of the other night does not count');
        self::assertSame('2027-03-10', Days::key($anchor->date));
        self::assertSame('2027-03-01', Days::key($anchor->capturedAt));
        self::assertSame(AnchorOrigin::Measured, $anchor->origin);
    }

    public function testPercentilesAreRoundedHalfToEven(): void
    {
        $samples = [self::sample('2027-03-10', 100.0), self::sample('2027-03-10', 201.0)];

        $whole = (new AnchorBuilder(EngineSample::rules()))->fromSamples(Days::date('2027-03-10'), $samples);
        self::assertSame([125.0, 150.0, 176.0, 191.0, 201.0], [$whole->p25, $whole->p50, $whole->p75, $whole->p90, $whole->max]);

        $cents = (new AnchorBuilder(EngineSample::rules(), 2))->fromSamples(Days::date('2027-03-10'), $samples);
        self::assertSame([125.25, 150.5, 175.75, 190.9, 201.0], [$cents->p25, $cents->p50, $cents->p75, $cents->p90, $cents->max]);
    }

    public function testOnlyTheNewestSampleOfEachSourceCounts(): void
    {
        $builder = new AnchorBuilder(EngineSample::rules());
        $anchor = $builder->fromSamples(Days::date('2027-03-10'), [
            self::sample('2027-03-10', 500.0, '2027-03-01', 'a'),
            self::sample('2027-03-10', 520.0, '2027-03-02', 'a'),
            self::sample('2027-03-10', 700.0, '2027-03-01', 'b'),
            self::sample('2027-03-10', 900.0, '2027-02-27'),
        ]);

        self::assertSame(3, $anchor->n);
        self::assertSame([610.0, 700.0, 900.0], [$anchor->p25, $anchor->p50, $anchor->max], 'from 520, 700 and 900: the older price of source "a" is out');
        self::assertSame('2027-03-02', Days::key($anchor->capturedAt), 'the anchor is dated by its newest sample');
    }

    public function testANightWithoutSamplesHasNoAnchor(): void
    {
        $builder = new AnchorBuilder(EngineSample::rules());

        self::assertNull($builder->fromSamples(Days::date('2027-03-10'), []));
        self::assertNull($builder->fromSamples(Days::date('2027-03-10'), [self::sample('2027-03-11', 500.0)]));
    }

    public function testBuildGivesOneAnchorPerNightInDateOrder(): void
    {
        $anchors = (new AnchorBuilder(EngineSample::rules()))->build([
            self::sample('2027-03-12', 800.0),
            self::sample('2027-03-10', 500.0),
            self::sample('2027-03-12', 1000.0),
        ]);

        self::assertSame(['2027-03-10', '2027-03-12'], array_keys($anchors));
        self::assertSame(1, $anchors['2027-03-10']->n);
        self::assertSame(900.0, $anchors['2027-03-12']->p50);
    }

    public function testAMeasuredAnchorBeatsAnEstimateWhileItIsFresh(): void
    {
        $builder = new AnchorBuilder(EngineSample::rules());
        $today = Days::date('2027-03-20');
        $points = [400.0, 600.0, 800.0, 1000.0, 1200.0];
        $estimate = static fn (string $captured): MarketAnchor => self::anchor('2027-03-25', $points, 0, $captured, AnchorOrigin::Fallback);
        $measured = static fn (string $captured): MarketAnchor => self::anchor('2027-03-25', $points, 10, $captured);

        self::assertFalse($builder->replaces($estimate('2027-03-19'), $measured('2027-03-06'), $today), 'measured 14 days ago: still fresh');
        self::assertTrue($builder->replaces($estimate('2027-03-19'), $measured('2027-03-05'), $today), 'measured 15 days ago: the newer estimate takes over');
        self::assertTrue($builder->replaces($measured('2027-03-15'), $estimate('2027-03-19'), $today), 'a fresh measurement replaces a newer estimate');
        self::assertFalse($builder->replaces($measured('2027-03-01'), $estimate('2027-03-19'), $today), 'a stale measurement does not');
    }

    public function testOtherwiseTheNewerCaptureWinsThenTheLargerSample(): void
    {
        $builder = new AnchorBuilder(EngineSample::rules());
        $today = Days::date('2027-03-20');
        $points = [400.0, 600.0, 800.0, 1000.0, 1200.0];
        $anchor = static fn (?string $captured, int $n): MarketAnchor => self::anchor('2027-03-25', $points, $n, $captured);

        self::assertTrue($builder->replaces($anchor('2027-03-12', 3), $anchor('2027-03-10', 30), $today));
        self::assertFalse($builder->replaces($anchor('2027-03-09', 30), $anchor('2027-03-10', 3), $today));
        self::assertFalse($builder->replaces($anchor('2027-03-10', 10), $anchor('2027-03-10', 12), $today));
        self::assertTrue($builder->replaces($anchor('2027-03-10', 12), $anchor('2027-03-10', 12), $today), 'an equal one replaces');
        self::assertTrue($builder->replaces($anchor('2027-03-10', 13), $anchor('2027-03-10', 12), $today));
        self::assertFalse($builder->replaces($anchor(null, 99), $anchor('2027-03-10', 1), $today), 'no capture date is older than any date');
        self::assertTrue($builder->replaces($anchor(null, 5), $anchor(null, 5), $today));
    }

    public function testMergeKeepsWhatIsNotReplaced(): void
    {
        $builder = new AnchorBuilder(EngineSample::rules());
        $points = [400.0, 600.0, 800.0, 1000.0, 1200.0];
        $existing = [
            '2027-03-25' => self::anchor('2027-03-25', $points, 10, '2027-03-10'),
            '2027-03-26' => self::anchor('2027-03-26', $points, 10, '2027-03-10'),
        ];
        $fresh = [
            '2027-03-26' => self::anchor('2027-03-26', $points, 4, '2027-03-12'),
            '2027-03-25' => self::anchor('2027-03-25', $points, 4, '2027-03-08'),
            '2027-03-24' => self::anchor('2027-03-24', $points, 4, '2027-03-12'),
        ];

        $merged = $builder->merge($existing, $fresh, Days::date('2027-03-20'));

        self::assertSame(['2027-03-24', '2027-03-25', '2027-03-26'], array_keys($merged));
        self::assertSame($fresh['2027-03-24'], $merged['2027-03-24'], 'a new night comes in');
        self::assertSame($existing['2027-03-25'], $merged['2027-03-25'], 'an older capture does not replace');
        self::assertSame($fresh['2027-03-26'], $merged['2027-03-26'], 'a newer capture replaces');
    }

    public function testAnAnchorIsWeakWhenItsSampleIsSmallOrOld(): void
    {
        $builder = new AnchorBuilder(EngineSample::rules());
        $today = Days::date('2027-03-20');

        self::assertFalse($builder->isWeak(EngineSample::anchor('2027-03-25', 5, '2027-03-06'), $today), '5 samples, 14 days: the limits themselves');
        self::assertTrue($builder->isWeak(EngineSample::anchor('2027-03-25', 4, '2027-03-19'), $today), 'under the minimum sample');
        self::assertTrue($builder->isWeak(EngineSample::anchor('2027-03-25', 50, '2027-03-05'), $today), '15 days old');
        self::assertTrue($builder->isWeak(EngineSample::anchor('2027-03-25', 0, '2027-03-19', AnchorOrigin::Fallback), $today), 'a fallback has no sample');
        self::assertFalse($builder->isWeak(EngineSample::anchor('2027-03-25', 5, null), $today), 'an unknown capture date counts as today');
        self::assertSame(15, $builder->ageInDays(EngineSample::anchor('2027-03-25', 50, '2027-03-05'), $today));
        self::assertSame(0, $builder->ageInDays(EngineSample::anchor('2027-03-25', 50, null), $today));
    }

    public function testMonthlyFallbacksAreTheMediansOfTheMeasuredAnchorsOfEachNightTypeAndMonth(): void
    {
        $builder = new AnchorBuilder(EngineSample::rules());
        $fallbacks = $builder->monthlyFallbacks([
            '2027-04-12' => self::anchor('2027-04-12', [10.0, 20.0, 30.0, 40.0, 50.0], 9, '2027-03-03'),
            '2027-03-12' => self::anchor('2027-03-12', [300.0, 400.0, 500.0, 600.0, 700.0], 9, '2027-03-01'),
            '2027-03-09' => self::anchor('2027-03-09', [110.0, 211.0, 310.0, 401.0, 511.0], 9, '2027-03-02'),
            '2027-03-08' => self::anchor('2027-03-08', [100.0, 200.0, 300.0, 400.0, 500.0], 9, '2027-03-01'),
            '2027-03-10' => self::anchor('2027-03-10', [9000.0, 9000.0, 9000.0, 9000.0, 9000.0], 0, '2027-03-04', AnchorOrigin::Fallback),
        ]);

        self::assertSame(['weekday', 'weekend'], array_keys($fallbacks));
        self::assertSame(['2027-03', '2027-04'], array_keys($fallbacks['weekday']));
        $march = $fallbacks['weekday']['2027-03'];
        self::assertSame([105.0, 206.0, 305.0, 400.0, 506.0], [$march->p25, $march->p50, $march->p75, $march->p90, $march->max], 'medians of two nights, halves rounded to even; the estimate is left out');
        self::assertSame('2027-03-15', Days::key($march->date));
        self::assertSame('2027-03-02', Days::key($march->capturedAt), 'the newest capture behind it');
        self::assertSame(0, $march->n);
        self::assertSame(AnchorOrigin::Fallback, $march->origin);
        self::assertSame(500.0, $fallbacks['weekend']['2027-03']->p75);
        self::assertSame(30.0, $fallbacks['weekday']['2027-04']->p75);
    }

    public function testNightsOfAPeriodFeedTheFallbackOfThePeriodsNightType(): void
    {
        $fallbacks = (new AnchorBuilder(EngineSample::rules()))->monthlyFallbacks([
            '2027-03-18' => self::anchor('2027-03-18', [1.0, 2.0, 3.0, 4.0, 5.0]),
            '2027-04-05' => self::anchor('2027-04-05', [6.0, 7.0, 8.0, 9.0, 10.0]),
        ]);

        self::assertSame(['holiday', 'weekend'], array_keys($fallbacks), 'a Thursday of the fair is a holiday night; a Monday of the local week a weekend night');
    }

    public function testRejectsAnOddNumberOfDecimals(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new AnchorBuilder(EngineSample::rules(), 5);
    }
}
