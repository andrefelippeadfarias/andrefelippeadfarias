<?php

declare(strict_types=1);

namespace Ritmo\Tests\Unit\Domain\Alerts;

use PHPUnit\Framework\TestCase;
use Ritmo\Domain\Alerts\AlertSeverity;
use Ritmo\Domain\Alerts\BelowExpected;
use Ritmo\Domain\Alerts\BelowExpectedConfig;
use Ritmo\Domain\Alerts\PriceBasis;
use Ritmo\Tests\Unit\Domain\Operations\MovementDetectorTest;

final class BelowExpectedTest extends TestCase
{
    private const PRICES = ['room-a' => ['2026-11-20' => 500.0, '2026-11-21' => 500.0]];

    public function testUnderTheCriticalThresholdTheFindingIsCritical(): void
    {
        $check = new BelowExpected(new BelowExpectedConfig(true));

        $low = $check->evaluate([MovementDetectorTest::booking('1', total: 400.0)], self::PRICES, 'inn');
        $mid = $check->evaluate([MovementDetectorTest::booking('2', total: 600.0)], self::PRICES, 'inn');

        self::assertSame(AlertSeverity::Critical, $low[0]->severity);
        self::assertSame(AlertSeverity::Warning, $mid[0]->severity);
        self::assertSame(0.6, $mid[0]->ratio);
    }

    public function testTheFactorComesFromThePropertyThenTheSiteThenTheDefault(): void
    {
        $config = new BelowExpectedConfig(true, 0.7, 0.5, 0.9, PriceBasis::Current, ['inn' => ['booking' => 0.5], '*' => ['booking' => 0.8, 'airbnb' => 0.7]]);

        self::assertSame(0.5, $config->factor('inn', 'booking'));
        self::assertSame(0.8, $config->factor('annex', 'booking'));
        self::assertSame(0.7, $config->factor('inn', 'airbnb'));
        self::assertSame(0.9, $config->factor('inn', 'direct'));
    }
}
