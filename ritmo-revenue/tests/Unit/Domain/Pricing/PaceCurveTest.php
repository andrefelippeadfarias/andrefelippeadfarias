<?php

declare(strict_types=1);

namespace Ritmo\Tests\Unit\Domain\Pricing;

use PHPUnit\Framework\TestCase;
use Ritmo\Domain\Model\NightType;
use Ritmo\Domain\Pricing\PaceCurve;
use Ritmo\Tests\Unit\Domain\EngineSample;

/**
 * Weekday curve of the sample: 60 days 5%, 30 days 10%, 14 days 20%, 7 days 35%, 3 days 50%,
 * 1 day 65%, the day itself 100%.
 */
final class PaceCurveTest extends TestCase
{
    public function testReturnsTheConfiguredPoints(): void
    {
        $curve = new PaceCurve(EngineSample::rules());

        foreach ([60 => 5.0, 30 => 10.0, 14 => 20.0, 7 => 35.0, 3 => 50.0, 1 => 65.0, 0 => 100.0] as $days => $percent) {
            self::assertSame($percent, $curve->expected(NightType::Weekday, $days), "D$days");
        }
        self::assertSame(55.0, $curve->expected(NightType::Weekend, 7));
        self::assertSame(70.0, $curve->expected(NightType::Holiday, 7));
    }

    public function testInterpolatesBetweenPoints(): void
    {
        $curve = new PaceCurve(EngineSample::rules());

        self::assertEqualsWithDelta(7.5, $curve->expected(NightType::Weekday, 45), 1e-9);
        self::assertEqualsWithDelta(42.5, $curve->expected(NightType::Weekday, 5), 1e-9);
        self::assertEqualsWithDelta(57.5, $curve->expected(NightType::Weekday, 2), 1e-9);
    }

    public function testIsFlatBeyondTheFarthestPointAndAfterTheNight(): void
    {
        $curve = new PaceCurve(EngineSample::rules());

        self::assertSame(5.0, $curve->expected(NightType::Weekday, 61));
        self::assertSame(5.0, $curve->expected(NightType::Weekday, 400));
        self::assertSame(100.0, $curve->expected(NightType::Weekday, -1));
    }
}
