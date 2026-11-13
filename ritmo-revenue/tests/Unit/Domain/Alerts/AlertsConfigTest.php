<?php

declare(strict_types=1);

namespace Ritmo\Tests\Unit\Domain\Alerts;

use PHPUnit\Framework\TestCase;
use Ritmo\Domain\Alerts\AlertsConfig;
use Ritmo\Domain\Alerts\PriceBasis;
use Ritmo\Domain\Config\InvalidConfig;
use Ritmo\Tests\Support\Fixtures;
use Ritmo\Tests\Support\ParityNames;

final class AlertsConfigTest extends TestCase
{
    public function testTheDefaultsAreNeutral(): void
    {
        $config = AlertsConfig::fromArray([]);

        self::assertTrue($config->security->enabled);
        self::assertFalse($config->security->originsConfirmed, 'origins are learnt until the owner confirms them (D4)');
        self::assertFalse($config->belowExpected->enabled, 'off until the channel factors are set (D2)');
        self::assertSame(PriceBasis::AtBooking, $config->belowExpected->priceBasis);
        self::assertSame([50, 3], [$config->inventoryMaxNightsListed, $config->connectorDownEscalateRuns]);
        self::assertEquals($config, new AlertsConfig());
    }

    public function testTheParityValuesLoad(): void
    {
        $config = AlertsConfig::fromArray(Fixtures::json('presets/parity-ops.json')['alerts']);

        self::assertSame(PriceBasis::Current, $config->belowExpected->priceBasis);
        self::assertSame(0.49, $config->belowExpected->factor(ParityNames::property('first'), 'booking'));
        self::assertSame(1.0, $config->belowExpected->factor(ParityNames::property('second'), 'expedia'));
    }

    public function testKnownOriginsAreComparedWithoutCaseAndSpaces(): void
    {
        $config = AlertsConfig::fromArray(['security' => ['known_origins' => ['Front-Desk', 'API'], 'origins_confirmed' => true]]);

        self::assertTrue($config->security->isKnown(' front-desk '));
        self::assertFalse($config->security->isKnown('someone'));
    }

    public function testInvalidValuesAreRefused(): void
    {
        try {
            AlertsConfig::fromArray(['below_expected' => ['threshold' => 0.4, 'critical_threshold' => 0.6, 'channel_factors' => ['inn' => ['booking' => 3]]]]);
            self::fail('accepted');
        } catch (InvalidConfig $e) {
            $paths = array_map(static fn ($error): string => $error->path, $e->errors());
            self::assertContains('alerts.below_expected.critical_threshold', $paths);
            self::assertContains('alerts.below_expected.channel_factors.inn.booking', $paths);
        }
    }
}
