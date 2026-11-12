<?php

declare(strict_types=1);

namespace Ritmo\Tests\Integration\Connectors;

use PHPUnit\Framework\TestCase;
use Ritmo\Connectors\PriceLabs\PriceLabsConfig;
use Ritmo\Connectors\PriceLabs\PriceLabsConnector;
use Ritmo\Connectors\Support\RequestBudget;
use Ritmo\Contracts\Exception\RateLimited;
use Ritmo\Contracts\HttpResponse;
use Ritmo\Domain\Support\Days;
use Ritmo\Infrastructure\Db\Migration\Migrator;
use Ritmo\Infrastructure\Db\PdoDatabase;
use Ritmo\Infrastructure\Db\Repository\QuotaLogRepository;
use Ritmo\Infrastructure\Http\DbQuotaLedger;
use Ritmo\Infrastructure\Http\RateLimit;
use Ritmo\Infrastructure\Http\RateLimiter;
use Ritmo\Infrastructure\Http\ThrottledHttpClient;
use Ritmo\Infrastructure\Store\ArrayStore;
use Ritmo\Tests\Fakes\ClockSleeper;
use Ritmo\Tests\Fakes\MovableClock;
use Ritmo\Tests\Fakes\RecordingLogger;
use Ritmo\Tests\Fakes\ScriptedHttpClient;

/**
 * The PriceLabs limits with the real infrastructure: the per-minute pace of the RateLimiter
 * built from the connector's constants, and the hourly budget kept in the database ledger.
 */
final class PriceLabsLimitsTest extends TestCase
{
    private MovableClock $clock;

    private ScriptedHttpClient $wire;

    private DbQuotaLedger $ledger;

    protected function setUp(): void
    {
        $this->clock = new MovableClock('2027-03-01T10:00:00Z');
        $this->wire = new ScriptedHttpClient(static fn (): HttpResponse => ScriptedHttpClient::json(200, ['overrides' => []]));

        $db = PdoDatabase::inMemory();
        Migrator::standard($db, new ArrayStore())->migrate();
        $this->ledger = new DbQuotaLedger(new QuotaLogRepository($db));
    }

    private function connector(ClockSleeper $sleeper): PriceLabsConnector
    {
        $limiter = new RateLimiter($this->clock, $sleeper, [
            RateLimit::perMinute(PriceLabsConnector::REQUESTS_PER_MINUTE),
            RateLimit::perHour(PriceLabsConnector::REQUESTS_PER_HOUR),
        ]);

        return new PriceLabsConnector(
            new ThrottledHttpClient($this->wire, $limiter, PriceLabsConnector::ID),
            new PriceLabsConfig('sim-pricelabs-key-0001', 'beds24'),
            $this->clock,
            $this->ledger,
            new RecordingLogger()
        );
    }

    private function readOnce(PriceLabsConnector $connector): void
    {
        $connector->readOverrides('901001___902001', Days::date('2027-03-01'), Days::date('2027-03-31'));
    }

    public function testTheSixtyFirstCallOfAMinuteWaitsForTheWindow(): void
    {
        $sleeper = new ClockSleeper($this->clock);
        $connector = $this->connector($sleeper);

        for ($call = 0; $call < PriceLabsConnector::REQUESTS_PER_MINUTE; ++$call) {
            $this->readOnce($connector);
        }
        self::assertSame([], $sleeper->waits(), 'sixty calls fit in a minute');

        $this->readOnce($connector);

        self::assertCount(1, $sleeper->waits());
        self::assertEqualsWithDelta(60.0, $sleeper->waits()[0], 0.01);
        self::assertCount(61, $this->wire->requests());
        self::assertSame(61, $this->ledger->countSince(PriceLabsConnector::ID, RequestBudget::ACTION, '', $this->clock->now()->modify('-1 hour')));
    }

    public function testTheHourlyBudgetInTheDatabaseStopsANewRunBeforeSending(): void
    {
        // An earlier run spent the hour: its calls are in the shared ledger, not in this process.
        $earlier = $this->clock->now()->modify('-30 minutes');
        for ($call = 0; $call < PriceLabsConnector::REQUESTS_PER_HOUR; ++$call) {
            $this->ledger->record(PriceLabsConnector::ID, RequestBudget::ACTION, '', $call % 7 !== 0, $earlier);
        }

        try {
            $this->readOnce($this->connector(new ClockSleeper($this->clock)));
            self::fail('A spent hour must stop the request.');
        } catch (RateLimited $e) {
            self::assertSame(PriceLabsConnector::ID, $e->provider());
            self::assertStringContainsString('no request was sent', $e->getMessage());
        }
        self::assertSame([], $this->wire->requests());

        $this->clock->advance(30 * 60 + 1);
        $this->readOnce($this->connector(new ClockSleeper($this->clock)));
        self::assertCount(1, $this->wire->requests());
    }
}
