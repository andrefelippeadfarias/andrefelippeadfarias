<?php

declare(strict_types=1);

namespace Ritmo\Tests\Unit\Domain\Pricing;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Ritmo\Domain\Model\NightType;
use Ritmo\Domain\Model\PaceStatus;
use Ritmo\Domain\Pricing\PaceMeter;
use Ritmo\Tests\Unit\Domain\EngineSample;

/**
 * Sample rules: band 15, very behind 25; nothing sold within 5 (weekday), 10 (weekend) or
 * 14 (holiday) days is very behind. A weekday night 7 days away expects 35% sold.
 */
final class PaceMeterTest extends TestCase
{
    public function testStatusFollowsTheGapToTheCurve(): void
    {
        $meter = new PaceMeter(EngineSample::rules());
        $status = static fn (int $sold): PaceStatus => $meter->measure(NightType::Weekday, 7, $sold, 100)->status;

        self::assertSame(PaceStatus::VeryBehind, $status(10), '25 points under: the limit itself');
        self::assertSame(PaceStatus::Behind, $status(11));
        self::assertSame(PaceStatus::Behind, $status(20), '15 points under: the limit itself');
        self::assertSame(PaceStatus::OnPace, $status(21));
        self::assertSame(PaceStatus::OnPace, $status(49));
        self::assertSame(PaceStatus::Ahead, $status(50), '15 points over: the limit itself');
        self::assertSame(PaceStatus::Ahead, $status(100));
    }

    public function testNothingSoldCloseToTheNightIsVeryBehind(): void
    {
        $meter = new PaceMeter(EngineSample::rules());

        // 30 days away a weekday expects 10%: with nothing sold the gap alone would be "on pace".
        self::assertSame(PaceStatus::OnPace, $meter->measure(NightType::Weekday, 30, 0, 4)->status);
        self::assertSame(PaceStatus::Behind, $meter->measure(NightType::Weekday, 14, 0, 4)->status, '20 points under');
        self::assertSame(PaceStatus::VeryBehind, $meter->measure(NightType::Weekday, 5, 0, 4)->status);
        self::assertSame(PaceStatus::VeryBehind, $meter->measure(NightType::Weekend, 10, 0, 4)->status);
        self::assertSame(PaceStatus::VeryBehind, $meter->measure(NightType::Holiday, 14, 0, 4)->status);

        $rules = EngineSample::rules(static function (array $data): array {
            $data['pace']['zero_sold_days'] = ['weekday' => 30, 'weekend' => 10, 'holiday' => 14];

            return $data;
        });
        self::assertSame(PaceStatus::VeryBehind, (new PaceMeter($rules))->measure(NightType::Weekday, 30, 0, 4)->status, 'the window is configurable');
        self::assertSame(PaceStatus::OnPace, (new PaceMeter($rules))->measure(NightType::Weekday, 31, 0, 4)->status);
    }

    public function testReadingCarriesTheSharesAndTheGap(): void
    {
        $reading = (new PaceMeter(EngineSample::rules()))->measure(NightType::Weekday, 7, 1, 4);

        self::assertSame(25.0, $reading->soldPercent);
        self::assertSame(35.0, $reading->expectedPercent);
        self::assertSame(-10.0, $reading->gap());
        self::assertSame(PaceStatus::OnPace, $reading->status);
    }

    public function testOverbookedAndUnitlessNights(): void
    {
        $meter = new PaceMeter(EngineSample::rules());

        self::assertSame(125.0, $meter->measure(NightType::Weekday, 7, 5, 4)->soldPercent);
        self::assertSame(0.0, $meter->measure(NightType::Weekday, 20, 2, 0)->soldPercent, 'no units on sale: nothing to measure');
    }

    public function testRejectsNegativeUnits(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new PaceMeter(EngineSample::rules()))->measure(NightType::Weekday, 7, 0, -1);
    }
}
