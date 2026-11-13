<?php

declare(strict_types=1);

namespace Ritmo\Tests\Unit\Application;

use PHPUnit\Framework\TestCase;
use Ritmo\Application\Dashboard\OccupancySignal;

final class DashboardTest extends TestCase
{
    public function testTheTrafficLightComparesAWindowWithItsGoal(): void
    {
        self::assertSame(OccupancySignal::GREEN, OccupancySignal::classify(60.0, 60.0));
        self::assertSame(OccupancySignal::AMBER, OccupancySignal::classify(59.9, 60.0));
        self::assertSame(OccupancySignal::AMBER, OccupancySignal::classify(50.0, 60.0));
        self::assertSame(OccupancySignal::RED, OccupancySignal::classify(49.9, 60.0));
        self::assertSame(OccupancySignal::UNKNOWN, OccupancySignal::classify(null, 60.0));
    }
}
