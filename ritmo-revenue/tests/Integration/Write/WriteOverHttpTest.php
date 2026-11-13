<?php

declare(strict_types=1);

namespace Ritmo\Tests\Integration\Write;

use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Ritmo\Application\EmergencySwitch;
use Ritmo\Application\Write\Execute\StopReason;
use Ritmo\Application\Write\Execute\WriteExecutor;
use Ritmo\Application\Write\Execute\WriteOutcome;
use Ritmo\Application\Write\Execute\WriteRun;
use Ritmo\Application\Write\Gate\AutonomousAuthorization;
use Ritmo\Application\Write\Gate\TonightState;
use Ritmo\Application\Write\Gate\WriteContext;
use Ritmo\Application\Write\Gate\WriteGate;
use Ritmo\Application\Write\Journal\OpStatus;
use Ritmo\Application\Write\Journal\StateReader;
use Ritmo\Application\Write\Journal\WriteJournal;
use Ritmo\Application\Write\Resume\ResumeService;
use Ritmo\Application\Write\Schedule\CalendarChange;
use Ritmo\Connectors\Beds24\Beds24Config;
use Ritmo\Connectors\Beds24\Beds24Connector;
use Ritmo\Connectors\Beds24\Beds24Credentials;
use Ritmo\Connectors\PriceLabs\PriceLabsConfig;
use Ritmo\Connectors\PriceLabs\PriceLabsConnector;
use Ritmo\Connectors\Support\BaseUrl;
use Ritmo\Contracts\Exception\WriteBlocked;
use Ritmo\Contracts\WriteToken;
use Ritmo\Domain\Plan\Plan;
use Ritmo\Domain\Plan\ValidatedPlan;
use Ritmo\Domain\Plan\Validator;
use Ritmo\Domain\Support\Days;
use Ritmo\Infrastructure\Db\Migration\Migrations;
use Ritmo\Infrastructure\Db\Migration\Migrator;
use Ritmo\Infrastructure\Db\PdoDatabase;
use Ritmo\Infrastructure\Db\Repository\WriteOpRepository;
use Ritmo\Infrastructure\Http\CurlHttpClient;
use Ritmo\Infrastructure\Http\GuardedHttpClient;
use Ritmo\Infrastructure\Http\ReadRouteAllowList;
use Ritmo\Infrastructure\Http\RequestTarget;
use Ritmo\Infrastructure\Store\ArrayStore;
use Ritmo\Tests\Fakes\FakeApiServer;
use Ritmo\Tests\Fakes\MemoryQuotaLedger;
use Ritmo\Tests\Fakes\MovableClock;
use Ritmo\Tests\Fakes\RecordingLogger;
use Ritmo\Tests\Integration\Write\Support\SimWorld;
use Ritmo\Tests\Unit\Domain\EngineSample;
use RuntimeException;

/**
 * The write path over real HTTP: cURL behind the guard, the simulator served by PHP's built-in
 * server on 127.0.0.1 with its writable world on. The base URLs point at the simulator, which
 * needs RITMO_DEV: every test runs in its own process.
 */
#[RunTestsInSeparateProcesses]
final class WriteOverHttpTest extends TestCase
{
    private FakeApiServer $server;
    private MovableClock $clock;
    private EmergencySwitch $switch;
    private WriteGate $gate;
    private WriteOpRepository $ops;
    private GuardedHttpClient $http;
    private PriceLabsConnector $pricing;
    private Beds24Connector $pms;

    protected function setUp(): void
    {
        if (!defined(BaseUrl::DEV_CONSTANT)) {
            define(BaseUrl::DEV_CONSTANT, true);
        }
        $this->server = FakeApiServer::start();
        $this->control('POST', '/__writes', ['not_ready' => []]);
        $this->clock = new MovableClock(SimWorld::NOW);
        $this->switch = new EmergencySwitch(new ArrayStore());
        $this->gate = new WriteGate($this->clock, $this->switch);
        $db = PdoDatabase::inMemory();
        (new Migrator($db, new ArrayStore(), Migrations::all()))->migrate();
        $this->ops = new WriteOpRepository($db);
        $logger = new RecordingLogger();
        $calendar = RequestTarget::fromUrl($this->server->priceLabsBaseUrl() . PriceLabsConnector::PRICES_PATH);
        $reads = ReadRouteAllowList::none()->with(PriceLabsConnector::ID, 'POST', $calendar->host, $calendar->path);
        $this->http = new GuardedHttpClient(new CurlHttpClient('RitmoTest'), $reads, $logger, SimWorld::writeRoutes($this->server->priceLabsBaseUrl(), $this->server->beds24BaseUrl()), $this->gate);
        $this->pricing = new PriceLabsConnector($this->http, new PriceLabsConfig('sim-pricelabs-key-0001', 'beds24', $this->server->priceLabsBaseUrl()), $this->clock, new MemoryQuotaLedger(), $logger, $this->gate);
        $this->pms = new Beds24Connector($this->http, new Beds24Config(Beds24Credentials::longLifeToken('sim-long-life-token-0001'), ['902001' => 'STD', '902002' => 'GDN'], [901001], $this->server->beds24BaseUrl(), 5.0, ['902001' => 4, '902002' => 2]), $this->clock, $logger, $this->gate);
    }

    protected function tearDown(): void
    {
        $this->server->stop();
    }

    public function testAWriteRunOverHttpWritesEachStepOnceAndReadsItBack(): void
    {
        $plan = $this->plan();
        $night = Days::date('2027-03-12');

        $outcome = $this->writeRun($plan, [new CalendarChange('STD', '902001', $night, $night, minStay: 2, minStayBefore: 1)]);

        self::assertNull($outcome->stopReason);
        self::assertSame(['stored', 'stored', 'stored'], array_map(static fn ($r): string => $r->status->value, $outcome->results));
        self::assertSame([
            'POST /pricelabs/v1/listings/' . SimWorld::STD . '/overrides',
            'POST /pricelabs/v1/listings/' . SimWorld::GDN . '/overrides',
            'POST /beds24/v2/inventory/rooms/calendar',
        ], $this->writeLines());
        self::assertSame('-6', $this->control('GET', '/__writes')['overrides'][SimWorld::STD]['2027-03-10']['min_price']);
    }

    public function testAnAnswerLostAfterTheWriteIsReadBackAndNeverResent(): void
    {
        $this->control('POST', '/__fault', ['method' => 'POST', 'path' => '/pricelabs/v1/listings/' . SimWorld::GDN . '/overrides', 'status' => 503, 'mode' => 'apply_then_fail']);

        $outcome = $this->writeRun($this->plan());
        self::assertSame(StopReason::UpstreamError, $outcome->stopReason);
        self::assertSame(['stored', 'unknown'], array_map(static fn ($r): string => $r->status->value, $outcome->results));

        $this->clock->advance(300);
        $report = (new ResumeService($this->ops))->resume(2, 'inn', new StateReader($this->pricing, $this->pms));
        self::assertSame([OpStatus::Stored], array_values($report->rows), 'the 503 came after the write applied');
        self::assertCount(2, $this->writeLines(), 'nothing was sent again');
    }

    public function testAForgedTokenAndAPmsPriceNeverLeaveTheSite(): void
    {
        $forged = (new ReflectionClass(WriteToken::class))->newInstanceWithoutConstructor();
        $refused = 0;
        $attempts = [
            fn () => $this->pricing->writeOverrides($forged, SimWorld::STD, [['date' => '2027-03-10', 'min_price' => '-6', 'min_price_type' => 'percent_base']]),
            fn () => $this->pms->writeCalendar($forged, [['roomId' => '902001', 'from' => '2027-03-12', 'to' => '2027-03-12', 'minStay' => 2]]),
            fn () => $this->pms->writeCalendar($forged, [['roomId' => '902001', 'from' => '2027-03-12', 'to' => '2027-03-12', 'price1' => 700]]),
            fn () => $this->http->write($forged, 'POST', $this->server->beds24BaseUrl() . '/bookings', [], '[]'),
        ];
        foreach ($attempts as $attempt) {
            try {
                $attempt();
            } catch (WriteBlocked) {
                ++$refused;
            }
        }

        self::assertSame(4, $refused);
        self::assertSame([], $this->writeLines(), 'the simulator log has no write');
    }

    private function plan(): ValidatedPlan
    {
        $plan = new Plan('inn', $this->clock->today('UTC'), $this->clock->now(), [SimWorld::item('STD', '2027-03-10', 450.0), SimWorld::item('GDN', '2027-03-11', 600.0)]);
        $result = (new Validator(EngineSample::rules(), $this->clock))->validate($plan, SimWorld::property(), SimWorld::rooms());
        self::assertNotNull($result->plan);

        return $result->plan;
    }

    /**
     * @param list<CalendarChange> $pms
     */
    private function writeRun(ValidatedPlan $plan, array $pms = []): WriteOutcome
    {
        $today = $this->clock->today('UTC');
        $tonight = [];
        foreach (SimWorld::rooms() as $room) {
            $tonight[$room->code] = new TonightState($room->code, $this->pricing->readPrices($room->pricingRef, $today, $today)[0]?->price, null);
        }
        $context = new WriteContext(SimWorld::property(), SimWorld::rooms(), $this->clock->now(), $tonight, static fn (): bool => true, 'beds24');
        $issued = $this->gate->issue($plan, new AutonomousAuthorization('inn', 1, $this->clock->now()), $context, $pms);
        $executor = new WriteExecutor($this->gate, $this->switch, $this->clock, new WriteJournal($this->ops, $this->clock));

        return $executor->execute(new WriteRun(1, SimWorld::property(), $plan, $issued, $this->pricing, $this->pms, static fn (): bool => true));
    }

    /**
     * @return list<string> "METHOD /path" of the writes the simulator received
     */
    private function writeLines(): array
    {
        return array_values(array_filter($this->server->lines(), static fn (string $l): bool => !str_starts_with($l, 'GET ') && !str_ends_with($l, '/listing_prices')));
    }

    /**
     * @param array<string, mixed> $body
     *
     * @return array<string, mixed>
     */
    private function control(string $method, string $path, array $body = []): array
    {
        $curl = curl_init($this->server->url($path));
        curl_setopt_array($curl, [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10]);
        if ($method !== 'GET') {
            curl_setopt_array($curl, [CURLOPT_HTTPHEADER => ['Content-Type: application/json'], CURLOPT_POSTFIELDS => json_encode($body, JSON_THROW_ON_ERROR)]);
        }
        $answer = curl_exec($curl);
        curl_close($curl);
        if (!is_string($answer)) {
            throw new RuntimeException('The simulator did not answer ' . $path);
        }

        return (array) json_decode($answer, true, 64, JSON_THROW_ON_ERROR);
    }
}
