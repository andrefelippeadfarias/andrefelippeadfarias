<?php

declare(strict_types=1);

namespace Ritmo\Tests\Unit\Connectors;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Ritmo\Connectors\Beds24\Beds24Config;
use Ritmo\Connectors\Beds24\Beds24Connector;
use Ritmo\Connectors\Beds24\Beds24Credentials;
use Ritmo\Connectors\PriceLabs\PriceLabsConfig;
use Ritmo\Connectors\PriceLabs\PriceLabsConnector;
use Ritmo\Contracts\Exception\ConnectorException;
use Ritmo\Contracts\Exception\NotReady;
use Ritmo\Contracts\Exception\WriteBlocked;
use Ritmo\Contracts\HttpClient;
use Ritmo\Contracts\HttpResponse;
use Ritmo\Contracts\WriteAuthority;
use Ritmo\Contracts\WriteHttpClient;
use Ritmo\Contracts\WriteOperation;
use Ritmo\Contracts\WriteRequest;
use Ritmo\Contracts\WriteToken;
use Ritmo\Domain\Support\Days;
use Ritmo\Infrastructure\Http\CreditAwareHttpClient;
use Ritmo\Infrastructure\Http\CreditHeaders;
use Ritmo\Infrastructure\Http\GuardedHttpClient;
use Ritmo\Infrastructure\Http\RateLimit;
use Ritmo\Infrastructure\Http\RateLimiter;
use Ritmo\Infrastructure\Http\ReadRouteAllowList;
use Ritmo\Infrastructure\Http\ThrottledHttpClient;
use Ritmo\Infrastructure\Http\WriteRouteAllowList;
use Ritmo\Tests\Fakes\ClockSleeper;
use Ritmo\Tests\Fakes\MemoryQuotaLedger;
use Ritmo\Tests\Fakes\MovableClock;
use Ritmo\Tests\Fakes\RecordingLogger;
use Ritmo\Tests\Fakes\ScriptedHttpClient;

/**
 * The write door of the HTTP layer and the connectors: the guard lets a write through only on a
 * declared write route with a live token (its read door stays read-only); the decorators pass
 * writes on and never repeat one; each connector asks the write authority with exactly what its
 * body changes, classifies the answers (not ready, refusal, lost answer) and renews a PMS token
 * once on a 401.
 */
final class WriteDoorTest extends TestCase
{
    private const OVERRIDES = 'https://api.pricelabs.co/v1/listings/L1/overrides';

    private RecordingLogger $logger;

    /** @var list<WriteRequest> */
    private array $asked = [];

    private bool $live = true;

    protected function setUp(): void
    {
        $this->logger = new RecordingLogger();
    }

    private function authority(): WriteAuthority
    {
        $test = $this;

        return new class ($test) implements WriteAuthority {
            public function __construct(private readonly WriteDoorTest $test)
            {
            }

            public function isLive(WriteToken $token): bool
            {
                return $this->test->isLive();
            }

            public function verify(WriteToken $token, WriteRequest $request): void
            {
                $this->test->asked($request);
            }
        };
    }

    public function isLive(): bool
    {
        return $this->live;
    }

    public function asked(WriteRequest $request): void
    {
        if (!$this->live) {
            throw WriteBlocked::forRequest('WRITE', $request->operation->value, 'the write token is not live');
        }
        $this->asked[] = $request;
    }

    private static function token(): WriteToken
    {
        return (new ReflectionClass(WriteToken::class))->newInstanceWithoutConstructor();
    }

    private function guard(HttpClient $wire): GuardedHttpClient
    {
        $routes = WriteRouteAllowList::none()
            ->withBase('pricelabs', 'POST', PriceLabsConnector::BASE_URL, '/listings/{id}/overrides')
            ->withBase('pricelabs', 'DELETE', PriceLabsConnector::BASE_URL, '/listings/{id}/overrides')
            ->withBase('pricelabs', 'POST', PriceLabsConnector::BASE_URL, PriceLabsConnector::RECALCULATION_PATH)
            ->withBase('beds24', 'POST', Beds24Connector::BASE_URL, Beds24Connector::CALENDAR_PATH);

        return new GuardedHttpClient($wire, ReadRouteAllowList::none(), $this->logger, $routes, $this->authority());
    }

    private function priceLabs(HttpClient $http): PriceLabsConnector
    {
        return new PriceLabsConnector($http, new PriceLabsConfig('key-0001', 'pms'), new MovableClock(), new MemoryQuotaLedger(), $this->logger, $this->authority());
    }

    private function beds24(HttpClient $http, bool $refreshable = false): Beds24Connector
    {
        $credentials = $refreshable ? Beds24Credentials::refreshToken('refresh-0001') : Beds24Credentials::longLifeToken('token-0001');

        return new Beds24Connector($http, new Beds24Config($credentials, [], [], null, 5.0, ['902001' => 4]), new MovableClock(), $this->logger, $this->authority());
    }

    public function testTheWriteAllowListTakesExactRoutesOnly(): void
    {
        $routes = WriteRouteAllowList::none()->withBase('pricelabs', 'DELETE', 'https://api.example.test:8443/v1', '/listings/{id}/overrides');

        self::assertTrue($routes->allows('DELETE', 'api.example.test:8443', '/v1/listings/abc___123/overrides'));
        self::assertSame([['connector' => 'pricelabs', 'method' => 'DELETE', 'host' => 'api.example.test:8443', 'pattern' => '/v1/listings/{id}/overrides']], $routes->routes());
        self::assertFalse($routes->allows('POST', 'api.example.test:8443', '/v1/listings/abc___123/overrides'));
        self::assertFalse($routes->allows('DELETE', 'api.example.test:8443', '/v1/listings/a/b/overrides'));
        self::assertFalse($routes->allows('DELETE', 'api.example.test:8443', '/v1/listings/../overrides'));
        foreach ([['GET', '/listings'], ['POST', '/bookings'], ['POST', '/v2/bookings/{id}'], ['POST', '/a/*']] as [$method, $pattern]) {
            try {
                WriteRouteAllowList::none()->with('x', $method, 'api.example.test', $pattern);
                self::fail($method . ' ' . $pattern . ' was declared');
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testTheGuardLetsAWriteThroughOnlyOnAWriteRouteWithALiveToken(): void
    {
        $wire = (new ScriptedHttpClient())->queue(new HttpResponse(200, [], '{}'));
        $guard = $this->guard($wire);

        $guard->write(self::token(), 'POST', self::OVERRIDES, [], '{}');
        self::assertSame(['POST /v1/listings/L1/overrides'], $wire->lines());

        foreach ([
            'read door' => fn () => $guard->request('POST', self::OVERRIDES, [], '{}'),
            'undeclared route' => fn () => $guard->write(self::token(), 'POST', 'https://api.pricelabs.co/v1/listings', [], '{}'),
            'booking route' => fn () => $guard->write(self::token(), 'POST', 'https://api.beds24.com/v2/bookings', [], '[]'),
            'override header' => fn () => $guard->write(self::token(), 'POST', self::OVERRIDES, ['X-HTTP-Method-Override' => 'PUT'], '{}'),
        ] as $what => $attempt) {
            try {
                $attempt();
                self::fail($what . ' went through');
            } catch (WriteBlocked) {
                $this->addToAssertionCount(1);
            }
        }
        $this->live = false;
        $this->expectException(WriteBlocked::class);
        $guard->write(self::token(), 'POST', self::OVERRIDES, [], '{}');
    }

    public function testTheDecoratorsPassAWriteOnOnceAndRefuseOverAReadOnlyClient(): void
    {
        $wire = (new ScriptedHttpClient())->queue(new HttpResponse(429, [], ''), new HttpResponse(200, [], '{}'));
        $clock = new MovableClock();
        $credit = new CreditAwareHttpClient($this->guard($wire), $clock, new ClockSleeper($clock), CreditHeaders::fiveMinuteWindow());
        $throttled = new ThrottledHttpClient($credit, new RateLimiter($clock, new ClockSleeper($clock), [RateLimit::perMinute(60)]), 'pricelabs');

        self::assertSame(429, $throttled->write(self::token(), 'POST', self::OVERRIDES, [], '{}')->status());
        self::assertCount(1, $wire->lines(), 'a write is never repeated, even after a 429');

        $readOnly = new ThrottledHttpClient(new ScriptedHttpClient(), new RateLimiter($clock, new ClockSleeper($clock), [RateLimit::perMinute(60)]), 'pricelabs');
        $this->expectException(WriteBlocked::class);
        $readOnly->write(self::token(), 'POST', self::OVERRIDES, [], '{}');
    }

    public function testThePricingConnectorAsksForExactlyWhatItsBodyChanges(): void
    {
        $wire = (new ScriptedHttpClient())->queue(new HttpResponse(200, [], '{"overrides":[]}'), new HttpResponse(204, [], ''), new HttpResponse(200, [], '{"success":true}'));
        $pricing = $this->priceLabs($this->guard($wire));

        $pricing->writeOverrides(self::token(), 'L1', [['date' => '2027-03-10', 'min_price' => '-9', 'min_price_type' => 'percent_base', 'currency' => 'EUR']]);
        $pricing->deleteOverrides(self::token(), 'L1', [Days::date('2027-03-11')]);
        $pricing->requestRecalculation(self::token(), 'L1');

        self::assertSame([WriteOperation::OverridesWrite, WriteOperation::OverridesDelete, WriteOperation::Recalculation], array_map(static fn (WriteRequest $r) => $r->operation, $this->asked));
        self::assertSame(['L1' => ['2027-03-10']], $this->asked[0]->nights);
        self::assertSame(['currency', 'min_price', 'min_price_type'], $this->asked[0]->fields);
        self::assertSame(['L1' => ['2027-03-11']], $this->asked[1]->nights);
        self::assertSame(['POST /v1/listings/L1/overrides', 'DELETE /v1/listings/L1/overrides', 'POST /v1/refresh_listing'], $wire->lines());
        self::assertSame('{"pms":"pms","update_children":false,"overrides":[{"date":"2027-03-11"}]}', $wire->requests()[1]['body']);
    }

    public function testThePricingAnswersAreClassified(): void
    {
        $wire = (new ScriptedHttpClient())->queue(
            new HttpResponse(400, [], '{"error":"Pricing data for this date isn\'t available yet"}'),
            new HttpResponse(400, [], '{"error":{"code":"INVALID_OVERRIDE"}}'),
            new ConnectorException('No response: timeout.', '', null, true),
        );
        $pricing = $this->priceLabs($this->guard($wire));
        $rule = [['date' => '2027-03-10', 'min_stay' => '2']];
        $caught = [];
        foreach ([1, 2, 3] as $attempt) {
            try {
                $pricing->writeOverrides(self::token(), 'L1', $rule);
            } catch (ConnectorException $e) {
                $caught[] = [$e::class, $e->httpStatus()];
            }
        }

        self::assertSame([[NotReady::class, 400], [ConnectorException::class, 400], [ConnectorException::class, null]], $caught);
    }

    public function testAReadOnlyConnectorNeverWrites(): void
    {
        $pricing = new PriceLabsConnector(new ScriptedHttpClient(), new PriceLabsConfig('key-0001', 'pms'), new MovableClock(), new MemoryQuotaLedger(), $this->logger);
        $this->expectException(WriteBlocked::class);
        $pricing->writeOverrides(self::token(), 'L1', [['date' => '2027-03-10', 'min_stay' => '2']]);
    }

    public function testThePmsConnectorRenewsItsTokenOnceAndStopsOnARefusal(): void
    {
        $wire = (new ScriptedHttpClient())->queue(
            new HttpResponse(200, [], '{"token":"access-1","expiresIn":86400}'),
            new HttpResponse(401, [], '{"success":false}'),
            new HttpResponse(200, [], '{"token":"access-2","expiresIn":86400}'),
            new HttpResponse(200, [], '[{"success":true}]'),
        );
        $range = ['roomId' => '902001', 'from' => '2027-03-12', 'to' => '2027-03-12', 'minStay' => 2];
        $this->beds24($this->guard($wire), true)->writeCalendar(self::token(), [$range]);

        self::assertSame(2, count(array_filter($wire->lines(), static fn (string $l): bool => str_starts_with($l, 'POST '))), 'the same body once more after the renewal');
        self::assertSame(['902001' => ['2027-03-12']], $this->asked[0]->nights);
        self::assertSame(['minStay'], $this->asked[0]->fields);

        $refused = (new ScriptedHttpClient())->queue(new HttpResponse(200, [], '[{"success":false,"errors":[]}]'));
        $this->expectException(ConnectorException::class);
        $this->beds24($this->guard($refused))->writeCalendar(self::token(), [$range]);
    }
}
