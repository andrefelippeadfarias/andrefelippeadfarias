<?php

declare(strict_types=1);

namespace Ritmo\Tests\Unit\Application\Licence;

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Ritmo\Application\Licence\LicenceSettings;

/**
 * The settings come from constants only; each case runs in its own process so a constant never
 * leaks into the rest of the suite.
 */
final class LicenceSettingsTest extends TestCase
{
    public function testWithoutConstantsThereIsNoServerAndNoDevelopmentMode(): void
    {
        $settings = LicenceSettings::fromConstants('https://inn.example.com', '1.0.0');

        self::assertNull($settings->server);
        self::assertFalse($settings->serverInvalid);
        self::assertFalse($settings->devMode, 'off by default');
        self::assertSame(30, $settings->grace->days);
        self::assertSame('https://inn.example.com', $settings->siteUrl);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testTheConstantsOfALiveSite(): void
    {
        define('RITMO_LICENCE_SERVER_URL', 'https://licences.example.com/v1/');
        define('RITMO_LICENCE_GRACE_DAYS', 14);
        define('RITMO_LICENCE_DEV', 'yes');

        $settings = LicenceSettings::fromConstants('https://inn.example.com', '1.0.0');

        self::assertSame('https://licences.example.com/v1', $settings->server?->url);
        self::assertSame(14, $settings->grace->days);
        self::assertFalse($settings->devMode, 'only exactly true turns development mode on');
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testPlainHttpNeedsDevelopmentModeAndABadAddressIsReported(): void
    {
        define('RITMO_LICENCE_SERVER_URL', 'http://127.0.0.1:8092/licence');

        $settings = LicenceSettings::fromConstants('https://inn.example.com', '1.0.0');

        self::assertNull($settings->server);
        self::assertTrue($settings->serverInvalid);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testTheLocalSimulatorInDevelopmentMode(): void
    {
        define('RITMO_LICENCE_SERVER_URL', 'http://127.0.0.1:8092/licence');
        define('RITMO_LICENCE_DEV', true);
        define('RITMO_LICENCE_GRACE_DAYS', 365);

        $settings = LicenceSettings::fromConstants('https://inn.example.com', '1.0.0');

        self::assertSame('http://127.0.0.1:8092/licence', $settings->server?->url);
        self::assertTrue($settings->devMode);
        self::assertSame(30, $settings->grace->days, 'a grace period over the limit falls back to the default');
    }
}
