<?php

declare(strict_types=1);

namespace Ritmo\Tests\Unit\Connectors\Beds24;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Ritmo\Connectors\Beds24\Beds24Config;
use Ritmo\Connectors\Beds24\Beds24Connector;
use Ritmo\Connectors\Beds24\Beds24Credentials;
use Ritmo\Contracts\Exception\AuthFailed;
use Ritmo\Contracts\Exception\ConnectorException;
use Ritmo\Contracts\Exception\NotAvailableInThisMilestone;
use Ritmo\Contracts\Exception\RateLimited;
use Ritmo\Contracts\HttpResponse;
use Ritmo\Contracts\WriteToken;
use Ritmo\Domain\Model\BookingRecord;
use Ritmo\Domain\Model\InventoryNight;
use Ritmo\Domain\Support\Days;
use Ritmo\Tests\Fakes\MovableClock;
use Ritmo\Tests\Fakes\RecordingLogger;
use Ritmo\Tests\Fakes\ScriptedHttpClient;

final class Beds24ConnectorTest extends TestCase
{
    private const BASE = 'https://api.beds24.com/v2';

    private const LONG_LIFE = 'sim-long-life-secret';

    private const REFRESH = 'sim-refresh-secret';

    private ScriptedHttpClient $http;

    private MovableClock $clock;

    private RecordingLogger $logger;

    protected function setUp(): void
    {
        $this->http = new ScriptedHttpClient();
        $this->clock = new MovableClock('2027-03-01T10:00:00Z');
        $this->logger = new RecordingLogger();
    }

    protected function tearDown(): void
    {
        foreach ($this->http->requests() as $request) {
            self::assertSame('GET', $request['method'], 'the Beds24 connector only ever sends GET');
        }
        self::assertSame(0, $this->http->pending(), 'every scripted reply was used');
        self::assertStringNotContainsString(self::LONG_LIFE, $this->logger->dump());
        self::assertStringNotContainsString(self::REFRESH, $this->logger->dump());
        self::assertStringNotContainsString('GUESTDATA', $this->logger->dump());
    }

    /**
     * @param array<string, string>    $roomMap
     * @param list<int|string>         $propertyIds
     */
    private function connector(?Beds24Credentials $credentials = null, array $roomMap = ['902001' => 'STD', '902002' => 'SUITE'], array $propertyIds = []): Beds24Connector
    {
        return new Beds24Connector(
            $this->http,
            new Beds24Config($credentials ?? Beds24Credentials::longLifeToken(self::LONG_LIFE), $roomMap, $propertyIds),
            $this->clock,
            $this->logger
        );
    }

    /**
     * One page of a Beds24 listing.
     *
     * @param list<array<string, mixed>> $data
     * @param array<string, string>      $headers
     */
    private static function page(array $data, bool $nextPage = false, array $headers = []): HttpResponse
    {
        return ScriptedHttpClient::json(200, ['success' => true, 'type' => 'x', 'count' => count($data), 'pages' => ['nextPageExists' => $nextPage, 'nextPageLink' => null], 'data' => $data], $headers);
    }

    private static function error(int $status, array $headers = []): HttpResponse
    {
        return ScriptedHttpClient::json($status, ['success' => false, 'type' => 'error', 'code' => $status, 'error' => 'Token is missing or not valid.'], $headers);
    }

    private static function token(string $token, ?int $expiresIn = 3600): HttpResponse
    {
        return ScriptedHttpClient::json(200, array_filter(['token' => $token, 'expiresIn' => $expiresIn], static fn ($v): bool => $v !== null));
    }

    public function testTheConnectionTestReadsThePropertiesWithTheLongLifeToken(): void
    {
        $this->http->queue(self::page([['id' => 901001, 'name' => 'Example Inn']]));

        $status = $this->connector()->testConnection();

        self::assertSame('beds24', $this->connector()->id());
        self::assertTrue($status->ok);
        self::assertSame('Connected to Beds24: 1 property visible.', $status->message);
        self::assertSame(['properties' => 1, 'property_names' => 'Example Inn'], $status->details);

        $request = $this->http->requests()[0];
        self::assertSame(self::BASE . '/properties?page=1', $request['url']);
        self::assertSame(['Accept' => 'application/json', 'token' => self::LONG_LIFE], $request['headers']);
        self::assertNull($request['body']);
    }

    public function testTheConnectionTestNeverThrows(): void
    {
        $this->http->queue(self::error(401), new ConnectorException('No response from the server.', 'beds24', null, true));

        $rejected = $this->connector()->testConnection();
        $unreachable = $this->connector()->testConnection();

        self::assertFalse($rejected->ok);
        self::assertSame(['http_status' => 401], $rejected->details);
        self::assertStringContainsString('rejected the credentials', $rejected->message);
        self::assertFalse($unreachable->ok);
        self::assertSame([], $unreachable->details);
    }

    public function testARefreshTokenIsExchangedOnceAndTheAccessTokenKeptUntilItExpires(): void
    {
        $connector = $this->connector(Beds24Credentials::refreshToken(self::REFRESH));
        $this->http->queue(self::token('access-1'), self::page([]), self::page([]), self::page([]), self::token('access-2'), self::page([]));

        $connector->testConnection();
        $connector->testConnection();
        $this->clock->advance(3600 - 300 - 1);
        $connector->testConnection();
        $this->clock->advance(1);
        $connector->testConnection();

        self::assertSame(
            ['GET /v2/authentication/token', 'GET /v2/properties', 'GET /v2/properties', 'GET /v2/properties', 'GET /v2/authentication/token', 'GET /v2/properties'],
            $this->http->lines()
        );
        $requests = $this->http->requests();
        self::assertSame(['Accept' => 'application/json', 'refreshToken' => self::REFRESH], $requests[0]['headers']);
        self::assertSame(['access-1', 'access-1', 'access-1', 'access-2'], [$requests[1]['headers']['token'], $requests[2]['headers']['token'], $requests[3]['headers']['token'], $requests[5]['headers']['token']]);
    }

    public function testAnAccessTokenWithoutALifetimeIsUsedUntilRejected(): void
    {
        $connector = $this->connector(Beds24Credentials::refreshToken(self::REFRESH));
        $this->http->queue(self::token('access-1', null), self::page([]), self::page([]));

        $connector->testConnection();
        $this->clock->advance(86400 * 3);
        $connector->testConnection();

        self::assertSame(['GET /v2/authentication/token', 'GET /v2/properties', 'GET /v2/properties'], $this->http->lines());
    }

    public function testARejectedAccessTokenIsRenewedAndTheRequestRepeatedOnce(): void
    {
        $connector = $this->connector(Beds24Credentials::refreshToken(self::REFRESH));
        $this->http->queue(self::token('access-1'), self::error(401), self::token('access-2'), self::page([['id' => 901001]]));

        self::assertTrue($connector->testConnection()->ok);

        self::assertSame(['GET /v2/authentication/token', 'GET /v2/properties', 'GET /v2/authentication/token', 'GET /v2/properties'], $this->http->lines());
        self::assertSame('access-2', $this->http->requests()[3]['headers']['token']);
        self::assertCount(1, $this->logger->entries('warning'));
    }

    public function testASecondRejectionIsAnAuthFailureWithoutAThirdTry(): void
    {
        $connector = $this->connector(Beds24Credentials::refreshToken(self::REFRESH));
        $this->http->queue(self::token('access-1'), self::error(401), self::token('access-2'), self::error(401));

        try {
            $connector->readBookingsByStay(Days::date('2027-03-01'), Days::date('2027-03-31'));
            self::fail('A second 401 must end in AuthFailed.');
        } catch (AuthFailed $e) {
            self::assertSame(401, $e->httpStatus());
            self::assertFalse($e->isRetryable());
        }

        self::assertSame(['GET /v2/authentication/token', 'GET /v2/bookings', 'GET /v2/authentication/token', 'GET /v2/bookings'], $this->http->lines());
    }

    public function testALongLifeTokenThatIsRejectedIsNotRetried(): void
    {
        $this->http->queue(self::error(401));

        $this->expectException(AuthFailed::class);
        try {
            $this->connector()->readBookingsModifiedSince(Days::date('2027-02-01'));
        } finally {
            self::assertCount(1, $this->http->requests());
        }
    }

    public function testARefusedRefreshTokenIsAnAuthFailure(): void
    {
        $this->http->queue(self::error(400));

        try {
            $this->connector(Beds24Credentials::refreshToken(self::REFRESH))->readInventory(['902001'], Days::date('2027-03-01'), Days::date('2027-03-02'));
            self::fail('A refused refresh token must end in AuthFailed.');
        } catch (AuthFailed $e) {
            self::assertSame(400, $e->httpStatus());
            self::assertStringNotContainsString(self::REFRESH, $e->getMessage());
        }
    }

    public function testATokenAnswerWithoutATokenIsAnError(): void
    {
        $this->http->queue(ScriptedHttpClient::json(200, ['expiresIn' => 3600]));

        $this->expectException(ConnectorException::class);
        $this->expectExceptionMessage('there is no access token');
        $this->connector(Beds24Credentials::refreshToken(self::REFRESH))->readBookingsByStay(Days::date('2027-03-01'), Days::date('2027-03-02'));
    }

    public function testA429IsRateLimitedWithTheResetTimeOfTheCreditWindow(): void
    {
        $this->http->queue(self::error(429, ['X-Five-Min-Limit-Remaining' => '-3', 'X-Five-Min-Limit-Resets-In' => '87.4', 'X-Request-Cost' => '1']));

        try {
            $this->connector()->readBookingsByStay(Days::date('2027-03-01'), Days::date('2027-03-31'));
            self::fail('A 429 must end in RateLimited.');
        } catch (RateLimited $e) {
            self::assertSame(429, $e->httpStatus());
            self::assertSame(88, $e->retryAfterSeconds());
            self::assertSame('beds24', $e->provider());
        }
    }

    public function testLowCreditStopsTheNextRequestBeforeItIsSent(): void
    {
        $connector = $this->connector();
        $this->http->queue(
            self::page([['id' => 901001]], false, ['X-Five-Min-Limit-Remaining' => '3', 'X-Five-Min-Limit-Resets-In' => '100', 'X-Request-Cost' => '1']),
            self::page([['id' => 901001]])
        );

        self::assertTrue($connector->testConnection()->ok);
        try {
            $connector->readBookingsByStay(Days::date('2027-03-01'), Days::date('2027-03-31'));
            self::fail('Low credit must stop the request.');
        } catch (RateLimited $e) {
            self::assertSame(100, $e->retryAfterSeconds());
            self::assertNull($e->httpStatus());
        }
        self::assertCount(1, $this->http->requests(), 'nothing was sent');

        $this->clock->advance(100);
        self::assertTrue($connector->testConnection()->ok);
        self::assertCount(2, $this->http->requests());
    }

    public function testServerErrorsAndBodiesOutsideTheFormatAreConnectorErrors(): void
    {
        $connector = $this->connector();
        $this->http->queue(
            self::error(503),
            new HttpResponse(200, [], 'not json'),
            ScriptedHttpClient::json(200, ['success' => false, 'error' => 'GUESTDATA echoed']),
            ScriptedHttpClient::json(200, ['success' => true, 'data' => 'nothing'])
        );

        $failures = [];
        for ($attempt = 0; $attempt < 4; ++$attempt) {
            try {
                $connector->readBookingsModifiedSince(Days::date('2027-02-01'));
            } catch (ConnectorException $e) {
                $failures[] = [$e::class, $e->isRetryable(), $e->httpStatus()];
                self::assertStringNotContainsString('GUESTDATA', $e->getMessage(), 'a body is never quoted');
            }
        }

        self::assertSame([
            [ConnectorException::class, true, 503],
            [ConnectorException::class, false, null],
            [ConnectorException::class, false, 200],
            [ConnectorException::class, false, null],
        ], $failures);
    }

    public function testBookingsByStayAskEveryStatusFollowPagesAndKeepOnlyStaysInTheRange(): void
    {
        $this->http->queue(
            self::page([BookingMapperTest::booking(['id' => 1, 'arrival' => '2027-03-08', 'departure' => '2027-03-10'])], true),
            self::page([BookingMapperTest::booking(['id' => 2, 'arrival' => '2027-03-09', 'departure' => '2027-03-11', 'roomId' => 902002, 'status' => 'cancelled'])], true),
            self::page([
                BookingMapperTest::booking(['id' => 3, 'arrival' => '2027-03-12', 'departure' => '2027-03-14', 'status' => 'black', 'roomId' => 902099]),
                BookingMapperTest::booking(['id' => 4, 'arrival' => '2027-03-09', 'departure' => '2027-03-10']),
            ])
        );

        $found = $this->connector(null, ['902001' => 'STD', '902002' => 'SUITE'], [901001])->readBookingsByStay(Days::date('2027-03-10'), Days::date('2027-03-12'));

        self::assertSame(['2', '3'], array_map(static fn (BookingRecord $b): string => $b->pmsRef, $found), 'bookings that leave on the first night have no night in the range');
        self::assertSame(['SUITE', null], array_map(static fn (BookingRecord $b): ?string => $b->roomCode, $found));
        self::assertStringNotContainsString('GUESTDATA', print_r($found, true));

        $requests = $this->http->requests();
        self::assertSame(['1', '2', '3'], array_map(static fn (array $r): string => $r['query']['page'][0], $requests));
        self::assertSame([
            'arrivalTo' => ['2027-03-12'],
            'departureFrom' => ['2027-03-10'],
            'status' => ['confirmed', 'new', 'request', 'cancelled', 'black', 'inquiry'],
            'includeInvoiceItems' => ['true'],
            'propertyId' => ['901001'],
            'page' => ['1'],
        ], $requests[0]['query']);
        self::assertSame('/v2/bookings', $requests[0]['path']);
    }

    public function testBookingsModifiedSinceAskFromTheInstantInUtcAndDropOlderChanges(): void
    {
        $this->http->queue(self::page([
            BookingMapperTest::booking(['id' => 1, 'modifiedTime' => '2027-02-20T11:59:59Z']),
            BookingMapperTest::booking(['id' => 2, 'modifiedTime' => '2027-02-20T12:00:00Z']),
            BookingMapperTest::booking(['id' => 3, 'modifiedTime' => null, 'bookingTime' => '2027-02-21T00:00:00Z']),
            BookingMapperTest::booking(['id' => 4, 'modifiedTime' => null, 'bookingTime' => null]),
        ]));

        $since = new \DateTimeImmutable('2027-02-20T09:00:00-03:00');
        $found = $this->connector()->readBookingsModifiedSince($since);

        self::assertSame(['2', '3', '4'], array_map(static fn (BookingRecord $b): string => $b->pmsRef, $found), 'without any timestamp the booking is kept');
        $query = $this->http->requests()[0]['query'];
        self::assertSame(['2027-02-20T12:00:00Z'], $query['modifiedFrom']);
        self::assertSame(['confirmed', 'new', 'request', 'cancelled', 'black', 'inquiry'], $query['status']);
        self::assertArrayNotHasKey('propertyId', $query, 'no property filter when none is configured');
    }

    public function testInventoryIsReadForTheAskedRoomsAndExpandedIntoNights(): void
    {
        $this->http->queue(
            self::page([
                ['roomId' => 902001, 'calendar' => [['from' => '2027-03-04', 'to' => '2027-03-05', 'numAvail' => 0, 'minStay' => 1, 'maxStay' => 30, 'override' => 'none']]],
                ['roomId' => 902099, 'calendar' => [['from' => '2027-03-04', 'to' => '2027-03-06', 'numAvail' => 9]]],
            ], true),
            self::page([
                ['roomId' => '902001', 'calendar' => [['from' => '2027-03-06', 'to' => '2027-03-09', 'numAvail' => 2, 'minStay' => 2, 'override' => 'noCheckIn']]],
            ])
        );

        $inventory = $this->connector()->readInventory([902001, '902002', '902001'], Days::date('2027-03-05'), Days::date('2027-03-07'));

        // PHP turns numeric array keys into integers; a lookup by the string ref still finds them.
        self::assertSame(['902001', '902002'], array_map('strval', array_keys($inventory)));
        self::assertSame([], $inventory['902002'], 'a room the PMS did not report has no nights');
        self::assertSame(
            [['2027-03-05', 0, 'none'], ['2027-03-06', 2, 'no_checkin'], ['2027-03-07', 2, 'no_checkin']],
            array_map(static fn (InventoryNight $n): array => [$n->date->format('Y-m-d'), $n->available, $n->restriction->value], $inventory['902001'])
        );
        self::assertNull($inventory['902001'][0]->pmsPrice);

        $query = $this->http->requests()[0]['query'];
        self::assertSame('/v2/inventory/rooms/calendar', $this->http->requests()[0]['path']);
        self::assertSame([
            'roomId' => ['902001', '902002'],
            'startDate' => ['2027-03-05'],
            'endDate' => ['2027-03-07'],
            'includeNumAvail' => ['true'],
            'includeMinStay' => ['true'],
            'includeMaxStay' => ['true'],
            'includeOverride' => ['true'],
            'page' => ['1'],
        ], $query);
    }

    public function testPricesAreAskedOnlyWhenWanted(): void
    {
        $this->http->queue(self::page([['roomId' => 902001, 'calendar' => [['from' => '2027-03-05', 'to' => '2027-03-05', 'numAvail' => 1, 'price1' => 455.5]]]]));

        $inventory = $this->connector()->readInventory(['902001'], Days::date('2027-03-05'), Days::date('2027-03-05'), true);

        self::assertSame(455.5, $inventory['902001'][0]->pmsPrice);
        self::assertSame(['true'], $this->http->requests()[0]['query']['includePrices']);
    }

    public function testNoRoomsMeansNoRequestAndABadRoomIdIsRefused(): void
    {
        self::assertSame([], $this->connector()->readInventory([], Days::date('2027-03-05'), Days::date('2027-03-07')));
        self::assertSame([], $this->http->requests());

        $this->expectException(InvalidArgumentException::class);
        $this->connector()->readInventory(['902001&roomId=1'], Days::date('2027-03-05'), Days::date('2027-03-07'));
    }

    public function testWritingTheCalendarIsNotAvailableAndSendsNothing(): void
    {
        $token = (new ReflectionClass(WriteToken::class))->newInstanceWithoutConstructor();

        try {
            $this->connector()->writeCalendar($token, [['room' => '902001', 'from' => '2027-03-05', 'to' => '2027-03-05', 'minStay' => 2]]);
            self::fail('Writing must not be available in this milestone.');
        } catch (NotAvailableInThisMilestone) {
            self::assertSame([], $this->http->requests());
        }
    }
}
