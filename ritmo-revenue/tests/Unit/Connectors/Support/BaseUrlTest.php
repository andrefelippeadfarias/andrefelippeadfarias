<?php

declare(strict_types=1);

namespace Ritmo\Tests\Unit\Connectors\Support;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Ritmo\Connectors\Beds24\Beds24Config;
use Ritmo\Connectors\Beds24\Beds24Connector;
use Ritmo\Connectors\Beds24\Beds24Credentials;
use Ritmo\Connectors\PriceLabs\PriceLabsConfig;
use Ritmo\Connectors\PriceLabs\PriceLabsConnector;
use Ritmo\Connectors\Support\BaseUrl;

final class BaseUrlTest extends TestCase
{
    private const PRODUCTION = 'https://api.example.test/v2';

    public function testWithoutAnOverrideTheProductionUrlIsUsedInAnyMode(): void
    {
        foreach ([true, false] as $devMode) {
            self::assertSame(self::PRODUCTION, BaseUrl::choose(self::PRODUCTION, null, $devMode));
            self::assertSame(self::PRODUCTION, BaseUrl::choose(self::PRODUCTION, '', $devMode));
            self::assertSame(self::PRODUCTION, BaseUrl::choose(self::PRODUCTION, self::PRODUCTION . '/', $devMode));
        }
    }

    public function testOutsideDevelopmentModeNoOverrideIsAccepted(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('RITMO_DEV');

        BaseUrl::choose(self::PRODUCTION, 'http://127.0.0.1:8099/beds24/v2', false);
    }

    public function testInDevelopmentModeAPlainHttpUrlReplacesTheProductionOne(): void
    {
        self::assertSame('http://127.0.0.1:8099/beds24/v2', BaseUrl::choose(self::PRODUCTION, 'http://127.0.0.1:8099/beds24/v2/', true));
        self::assertSame('https://sandbox.example.test', BaseUrl::choose(self::PRODUCTION, 'https://sandbox.example.test', true));
    }

    #[DataProvider('unsafeUrls')]
    public function testEvenInDevelopmentModeAnUnsafeUrlIsRefused(string $url): void
    {
        $this->expectException(InvalidArgumentException::class);

        BaseUrl::choose(self::PRODUCTION, $url, true);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function unsafeUrls(): array
    {
        return [
            'credentials' => ['http://user:secret@127.0.0.1:8099/v2'],
            'query' => ['http://127.0.0.1:8099/v2?token=x'],
            'fragment' => ['http://127.0.0.1:8099/v2#x'],
            'other scheme' => ['ftp://127.0.0.1/v2'],
            'no scheme' => ['127.0.0.1:8099/v2'],
            'space' => ['http://127.0.0.1:8099/v 2'],
            'control character' => ["http://127.0.0.1:8099/v2\n"],
        ];
    }

    public function testTheProductionUrlsAreFixedConstants(): void
    {
        self::assertSame('https://api.beds24.com/v2', Beds24Connector::BASE_URL);
        self::assertSame('https://api.pricelabs.co/v1', PriceLabsConnector::BASE_URL);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testWithoutTheDevConstantTheConfigurationsRefuseAnOverride(): void
    {
        self::assertFalse(defined(BaseUrl::DEV_CONSTANT));
        self::assertFalse(BaseUrl::devMode());
        self::assertSame(Beds24Connector::BASE_URL, (new Beds24Config(Beds24Credentials::longLifeToken('t0k3n')))->baseUrl);
        self::assertSame(PriceLabsConnector::BASE_URL, (new PriceLabsConfig('k3y', 'beds24'))->baseUrl);

        $refused = 0;
        foreach ([
            static fn () => new Beds24Config(Beds24Credentials::longLifeToken('t0k3n'), [], [], 'http://127.0.0.1:8099/beds24/v2'),
            static fn () => new PriceLabsConfig('k3y', 'beds24', 'http://127.0.0.1:8099/pricelabs/v1'),
        ] as $build) {
            try {
                $build();
            } catch (InvalidArgumentException) {
                ++$refused;
            }
        }
        self::assertSame(2, $refused);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testADevConstantThatIsNotExactlyTrueDoesNotCount(): void
    {
        define(BaseUrl::DEV_CONSTANT, 1);

        self::assertFalse(BaseUrl::devMode());
        $this->expectException(InvalidArgumentException::class);
        BaseUrl::resolve(self::PRODUCTION, 'http://127.0.0.1:8099/v2');
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testWithTheDevConstantTheConfigurationsPointAtTheSimulator(): void
    {
        define(BaseUrl::DEV_CONSTANT, true);

        self::assertSame('http://127.0.0.1:8099/beds24/v2', (new Beds24Config(Beds24Credentials::longLifeToken('t0k3n'), [], [], 'http://127.0.0.1:8099/beds24/v2'))->baseUrl);
        self::assertSame('http://127.0.0.1:8099/pricelabs/v1', (new PriceLabsConfig('k3y', 'beds24', 'http://127.0.0.1:8099/pricelabs/v1/'))->baseUrl);
    }
}
