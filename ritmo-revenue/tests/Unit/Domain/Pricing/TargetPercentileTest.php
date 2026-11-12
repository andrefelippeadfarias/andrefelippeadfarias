<?php

declare(strict_types=1);

namespace Ritmo\Tests\Unit\Domain\Pricing;

use PHPUnit\Framework\TestCase;
use Ritmo\Domain\Model\PaceStatus;
use Ritmo\Domain\Pricing\TargetPercentile;
use Ritmo\Tests\Unit\Domain\EngineSample;

/**
 * Sample tables: standard 70 (ahead and close) / 60 / 50 / 40 / 30, premium ten points higher;
 * "close" is 7 days or fewer.
 */
final class TargetPercentileTest extends TestCase
{
    public function testEachStatusHasItsTarget(): void
    {
        $target = new TargetPercentile(EngineSample::rules());
        $property = EngineSample::property();

        self::assertSame(60.0, $target->forNight($property, PaceStatus::Ahead, 20));
        self::assertSame(50.0, $target->forNight($property, PaceStatus::OnPace, 20));
        self::assertSame(40.0, $target->forNight($property, PaceStatus::Behind, 20));
        self::assertSame(30.0, $target->forNight($property, PaceStatus::VeryBehind, 20));
    }

    public function testANightAheadAndCloseAimsHigher(): void
    {
        $target = new TargetPercentile(EngineSample::rules());
        $property = EngineSample::property();

        self::assertSame(70.0, $target->forNight($property, PaceStatus::Ahead, 7));
        self::assertSame(60.0, $target->forNight($property, PaceStatus::Ahead, 8));
        self::assertSame(50.0, $target->forNight($property, PaceStatus::OnPace, 2), 'only a night that is ahead');
        self::assertTrue($target->isAheadNear(PaceStatus::Ahead, 7));
        self::assertFalse($target->isAheadNear(PaceStatus::Ahead, 8));
        self::assertFalse($target->isAheadNear(PaceStatus::Behind, 1));
    }

    public function testTheTierOfThePropertyChoosesTheTable(): void
    {
        $target = new TargetPercentile(EngineSample::rules());

        self::assertSame(60.0, $target->forNight(EngineSample::property('premium'), PaceStatus::OnPace, 20));
        self::assertSame(80.0, $target->forNight(EngineSample::property('premium'), PaceStatus::Ahead, 3));
        self::assertSame(50.0, $target->forNight(EngineSample::property('boutique'), PaceStatus::OnPace, 20), 'a tier without a table uses the standard one');
    }
}
