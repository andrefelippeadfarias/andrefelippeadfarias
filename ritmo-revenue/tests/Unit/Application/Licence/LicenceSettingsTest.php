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
    public function testALiveSiteCannotPointTheLicenceElsewhereOrTurnOnTheTestProvider(): void
    {
        define('RITMO_LICENCE_SERVER_URL', 'https://licences.example.com/v1/');
        define('RITMO_LICENCE_PUBLIC_KEY', base64_encode(str_repeat('k', 32)));
        define('RITMO_LICENCE_GRACE_DAYS', 14);
        define('RITMO_LICENCE_DEV', true);
        define('RITMO_DEV', true);

        $settings = LicenceSettings::fromConstants('https://inn.example.com', '1.0.0');

        self::assertNull($settings->server, 'the constant only counts on a development site');
        self::assertSame([], $settings->publicKeys, 'nor does the public key');
        self::assertTrue($settings->serverInvalid, 'the screen says the constant is not used');
        self::assertFalse($settings->devMode, 'the test provider needs a loopback site');
        self::assertSame(14, $settings->grace->days);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testPlainHttpNeedsDevelopmentModeAndABadAddressIsReported(): void
    {
        define('RITMO_LICENCE_SERVER_URL', 'http://127.0.0.1:8092/licence');

        $settings = LicenceSettings::fromConstants('http://127.0.0.1:8080', '1.0.0');

        self::assertNull($settings->server);
        self::assertTrue($settings->serverInvalid);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testWithoutAPublicKeyTheServerIsNotUsed(): void
    {
        define('RITMO_LICENCE_SERVER_URL', 'http://127.0.0.1:8092/licence');
        define('RITMO_DEV', true);

        $settings = LicenceSettings::fromConstants('http://127.0.0.1:8080', '1.0.0');

        self::assertNull($settings->server);
        self::assertTrue($settings->serverInvalid);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testTheLocalSimulatorInDevelopmentMode(): void
    {
        define('RITMO_LICENCE_SERVER_URL', 'http://127.0.0.1:8092/licence');
        define('RITMO_LICENCE_PUBLIC_KEY', base64_encode(str_repeat('k', 32)));
        define('RITMO_LICENCE_DEV', true);
        define('RITMO_DEV', true);
        define('RITMO_LICENCE_GRACE_DAYS', 365);

        $settings = LicenceSettings::fromConstants('http://127.0.0.1:8080', '1.0.0');

        self::assertSame('http://127.0.0.1:8092/licence', $settings->server?->url);
        self::assertSame([str_repeat('k', 32)], $settings->publicKeys);
        self::assertFalse($settings->serverInvalid);
        self::assertTrue($settings->devMode);
        self::assertSame(30, $settings->grace->days, 'a grace period over the limit falls back to the default');
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testTheTestProviderNeedsBothDevelopmentConstants(): void
    {
        define('RITMO_LICENCE_DEV', true);

        self::assertFalse(LicenceSettings::fromConstants('http://localhost:8080', '1.0.0')->devMode);
        self::assertTrue(LicenceSettings::isLoopback('http://inn.localhost'));
        self::assertFalse(LicenceSettings::isLoopback('https://127.0.0.1.example.com'));
    }
}
