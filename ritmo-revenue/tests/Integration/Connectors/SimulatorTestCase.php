<?php

declare(strict_types=1);

namespace Ritmo\Tests\Integration\Connectors;

use PHPUnit\Framework\TestCase;
use Ritmo\Connectors\Beds24\Beds24Config;
use Ritmo\Connectors\Beds24\Beds24Connector;
use Ritmo\Connectors\Beds24\Beds24Credentials;
use Ritmo\Connectors\PriceLabs\PriceLabsConfig;
use Ritmo\Connectors\PriceLabs\PriceLabsConnector;
use Ritmo\Connectors\Support\BaseUrl;
use Ritmo\Contracts\HttpClient;
use Ritmo\Infrastructure\Http\CurlHttpClient;
use Ritmo\Infrastructure\Http\GuardedHttpClient;
use Ritmo\Infrastructure\Http\ReadRouteAllowList;
use Ritmo\Infrastructure\Http\RequestTarget;
use Ritmo\Tests\Fakes\FakeApiServer;
use Ritmo\Tests\Fakes\MemoryQuotaLedger;
use Ritmo\Tests\Fakes\MovableClock;
use Ritmo\Tests\Fakes\RecordingLogger;

/**
 * The connectors against the simulator on 127.0.0.1, through real cURL behind the read-only
 * guard. Pointing a connector at the simulator needs RITMO_DEV, a constant: concrete tests run
 * in separate processes so it never leaks into the rest of the suite.
 */
abstract class SimulatorTestCase extends TestCase
{
    protected const BEDS24_TOKEN = 'sim-long-life-token-0001';

    protected const BEDS24_REFRESH = 'sim-refresh-token-0001';

    protected const PRICELABS_KEY = 'sim-pricelabs-key-0001';

    /** Beds24 room id => room type code; room 902003 is left unmapped on purpose. */
    protected const ROOM_MAP = ['902001' => 'STD', '902002' => 'GDN'];

    /** What may reach the APIs in M1: reads, and the calendar read of the pricing system. */
    protected const CALENDAR_READ = 'POST /pricelabs/v1/listing_prices';

    protected FakeApiServer $server;

    protected RecordingLogger $logger;

    protected MovableClock $clock;

    protected MemoryQuotaLedger $ledger;

    protected function setUp(): void
    {
        if (!defined(BaseUrl::DEV_CONSTANT)) {
            define(BaseUrl::DEV_CONSTANT, true);
        }
        $this->server = FakeApiServer::start();
        $this->logger = new RecordingLogger();
        $this->clock = new MovableClock('2027-02-28T12:00:00Z');
        $this->ledger = new MemoryQuotaLedger();
    }

    protected function tearDown(): void
    {
        $this->server->stop();
        self::assertStringNotContainsString('GUESTDATA', $this->logger->dump());
        foreach ([self::BEDS24_TOKEN, self::BEDS24_REFRESH, self::PRICELABS_KEY] as $secret) {
            self::assertStringNotContainsString($secret, $this->logger->dump());
        }
    }

    /**
     * Real cURL behind the read-only guard; the calendar read of the pricing system passes only
     * when it is put on the allow-list.
     */
    protected function guardedHttp(bool $allowCalendarRead): GuardedHttpClient
    {
        $routes = ReadRouteAllowList::none();
        if ($allowCalendarRead) {
            $target = RequestTarget::fromUrl($this->server->priceLabsBaseUrl() . PriceLabsConnector::PRICES_PATH);
            $routes = $routes->with(PriceLabsConnector::ID, 'POST', $target->host, $target->path);
        }

        return new GuardedHttpClient(new CurlHttpClient('RitmoTest'), $routes, $this->logger);
    }

    protected function beds24(?Beds24Credentials $credentials = null, ?HttpClient $http = null): Beds24Connector
    {
        return new Beds24Connector(
            $http ?? $this->guardedHttp(false),
            new Beds24Config($credentials ?? Beds24Credentials::longLifeToken(self::BEDS24_TOKEN), self::ROOM_MAP, [901001], $this->server->beds24BaseUrl()),
            $this->clock,
            $this->logger
        );
    }

    protected function priceLabs(?HttpClient $http = null, string $key = self::PRICELABS_KEY): PriceLabsConnector
    {
        return new PriceLabsConnector(
            $http ?? $this->guardedHttp(true),
            new PriceLabsConfig($key, 'beds24', $this->server->priceLabsBaseUrl()),
            $this->clock,
            $this->ledger,
            $this->logger
        );
    }

    protected function assertOnlyReadsReachedTheApis(): void
    {
        foreach ($this->server->lines() as $line) {
            self::assertTrue(str_starts_with($line, 'GET ') || $line === self::CALENDAR_READ, 'a write reached the simulator: ' . $line);
        }
    }
}
