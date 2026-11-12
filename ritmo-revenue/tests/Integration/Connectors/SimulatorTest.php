<?php

declare(strict_types=1);

namespace Ritmo\Tests\Integration\Connectors;

use PHPUnit\Framework\TestCase;
use Ritmo\Contracts\HttpResponse;
use Ritmo\Infrastructure\Http\CurlHttpClient;
use Ritmo\Tests\Fakes\FakeApiServer;
use Ritmo\Tests\Support\Fixtures;
use RitmoFakeApis\Scenario;

require_once dirname(__DIR__, 2) . '/fake-apis/router.php';

/**
 * The simulator itself: what it logs, what it refuses and that its invented world is coherent.
 * Requests go out with a bare cURL client (no read-only guard), so writes really reach it.
 */
final class SimulatorTest extends TestCase
{
    private const BEDS24_TOKEN = 'sim-long-life-token-0001';

    private const PRICELABS_KEY = 'sim-pricelabs-key-0001';

    private static FakeApiServer $server;

    public static function setUpBeforeClass(): void
    {
        self::$server = FakeApiServer::start();
    }

    public static function tearDownAfterClass(): void
    {
        self::$server->stop();
    }

    protected function setUp(): void
    {
        self::$server->reset();
    }

    /**
     * @param array<string, string> $headers
     */
    private static function send(string $method, string $path, array $headers = [], ?string $body = null): HttpResponse
    {
        return (new CurlHttpClient('RitmoTest'))->request($method, self::$server->url($path), $headers, $body, 10);
    }

    public function testEveryRequestIsLoggedAndEveryWriteRefused(): void
    {
        $writes = [
            ['POST', '/beds24/v2/bookings', ['token' => self::BEDS24_TOKEN]],
            ['POST', '/beds24/v2/inventory/rooms/calendar', ['token' => self::BEDS24_TOKEN]],
            ['POST', '/pricelabs/v1/listings/901001___902001/overrides', ['X-API-Key' => self::PRICELABS_KEY]],
            ['DELETE', '/pricelabs/v1/listings/901001___902001/overrides', ['X-API-Key' => self::PRICELABS_KEY]],
            ['PUT', '/pricelabs/v1/listings', ['X-API-Key' => self::PRICELABS_KEY]],
            ['POST', '/pricelabs/v1/refresh_listing', ['X-API-Key' => self::PRICELABS_KEY]],
        ];
        foreach ($writes as [$method, $path, $headers]) {
            self::assertSame(405, self::send($method, $path, $headers, '{}')->status(), $method . ' ' . $path);
        }
        self::assertSame(200, self::send('GET', '/pricelabs/v1/listings', ['X-API-Key' => self::PRICELABS_KEY])->status());

        $logged = self::$server->requests();
        self::assertSame(
            ['POST /beds24/v2/bookings', 'POST /beds24/v2/inventory/rooms/calendar', 'POST /pricelabs/v1/listings/901001___902001/overrides', 'DELETE /pricelabs/v1/listings/901001___902001/overrides', 'PUT /pricelabs/v1/listings', 'POST /pricelabs/v1/refresh_listing', 'GET /pricelabs/v1/listings'],
            self::$server->lines()
        );
        self::assertSame([405, 405, 405, 405, 405, 405, 200], array_column($logged, 'status'));

        self::$server->reset();
        self::assertSame([], self::$server->requests());
    }

    public function testCredentialsAreChecked(): void
    {
        self::assertSame(401, self::send('GET', '/beds24/v2/properties')->status());
        self::assertSame(401, self::send('GET', '/beds24/v2/properties', ['token' => 'forged'])->status());
        self::assertSame(401, self::send('GET', '/beds24/v2/authentication/token', ['refreshToken' => 'forged'])->status());
        self::assertSame('API_KEY_MISSING', self::send('GET', '/pricelabs/v1/listings')->json()['error']['code']);
        $invalid = self::send('GET', '/pricelabs/v1/listings', ['X-API-Key' => 'forged']);
        self::assertSame([403, 'API_KEY_INVALID'], [$invalid->status(), $invalid->json()['error']['code']]);

        $token = self::send('GET', '/beds24/v2/authentication/token', ['refreshToken' => 'sim-refresh-token-0001'])->json();
        self::assertSame(3600, $token['expiresIn']);
        self::assertSame(200, self::send('GET', '/beds24/v2/properties', ['token' => $token['token']])->status());
        self::$server->revokeAccessTokens();
        self::assertSame(401, self::send('GET', '/beds24/v2/properties', ['token' => $token['token']])->status());
    }

    public function testBeds24AnswersCarryTheCreditHeadersAndFaultsAnswerAsAsked(): void
    {
        $answer = self::send('GET', '/beds24/v2/properties', ['token' => self::BEDS24_TOKEN]);
        self::assertSame(['96.0', '280', '1'], [$answer->header('X-Five-Min-Limit-Remaining'), $answer->header('X-Five-Min-Limit-Resets-In'), $answer->header('X-Request-Cost')]);

        self::$server->fault('GET', '/beds24/v2/bookings', 429, ['X-Five-Min-Limit-Remaining' => '-4', 'X-Five-Min-Limit-Resets-In' => '45'], 2);
        self::$server->fault('GET', '/beds24/v2/properties', 0, ['X-Five-Min-Limit-Remaining' => '2']);
        $statuses = [];
        for ($attempt = 0; $attempt < 3; ++$attempt) {
            $reply = self::send('GET', '/beds24/v2/bookings', ['token' => self::BEDS24_TOKEN]);
            $statuses[] = [$reply->status(), $reply->header('X-Five-Min-Limit-Remaining'), $reply->header('X-Five-Min-Limit-Resets-In')];
        }
        $lowCredit = self::send('GET', '/beds24/v2/properties', ['token' => self::BEDS24_TOKEN]);

        self::assertSame([[429, '-4', '45'], [429, '-4', '45'], [200, '96.0', '280']], $statuses);
        self::assertSame([200, '2', '280'], [$lowCredit->status(), $lowCredit->header('X-Five-Min-Limit-Remaining'), $lowCredit->header('X-Five-Min-Limit-Resets-In')]);
    }

    public function testBookingsArePagedAndFiltered(): void
    {
        $pages = [];
        for ($page = 1; $page <= 3; ++$page) {
            $answer = self::send('GET', '/beds24/v2/bookings?includeInvoiceItems=true&page=' . $page, ['token' => self::BEDS24_TOKEN])->json();
            $pages[] = [array_column($answer['data'], 'id'), $answer['pages']['nextPageExists']];
        }
        self::assertSame([[[7001, 7002, 7003, 7004], true], [[7005, 7006, 7007, 7008], true], [[7009, 7010, 7011, 7012], false]], $pages);

        $filtered = self::send('GET', '/beds24/v2/bookings?status=black&status=inquiry&arrivalTo=2027-04-05&departureFrom=2027-04-03', ['token' => self::BEDS24_TOKEN])->json();
        self::assertSame([7009, 7011], array_column($filtered['data'], 'id'));
        self::assertArrayNotHasKey('invoiceItems', $filtered['data'][0], 'invoice items only when asked');
        self::assertStringStartsWith('GUESTDATA-', $filtered['data'][0]['firstName'], 'guest fields are there for the connector to drop');
    }

    public function testThePmsAndThePricingSystemTellTheSameStory(): void
    {
        $calendar = self::send(
            'GET',
            '/beds24/v2/inventory/rooms/calendar?roomId=902001&roomId=902002&roomId=902003&startDate=2027-03-01&endDate=2027-04-29&includeNumAvail=true&includeOverride=true&includeMinStay=true',
            ['token' => self::BEDS24_TOKEN]
        )->json()['data'];
        $prices = self::send('POST', '/pricelabs/v1/listing_prices', ['X-API-Key' => self::PRICELABS_KEY, 'Content-Type' => 'application/json'], json_encode(['listings' => [
            ['id' => '901001___902001', 'pms' => 'beds24', 'dateFrom' => '2027-03-01', 'dateTo' => '2027-04-29'],
            ['id' => '901001___902002', 'pms' => 'beds24', 'dateFrom' => '2027-03-13', 'dateTo' => '2027-03-13'],
            ['id' => 'unknown', 'pms' => 'beds24', 'dateFrom' => '2027-03-13', 'dateTo' => '2027-03-13'],
        ]], JSON_THROW_ON_ERROR))->json();

        $day = static function (array $room, string $night): array {
            foreach ($room['calendar'] as $span) {
                if ($span['from'] <= $night && $night <= $span['to']) {
                    return $span;
                }
            }

            return [];
        };
        self::assertSame(0, $day($calendar[0], '2027-03-05')['numAvail'], 'sold out: 4 of 4');
        self::assertSame(-1, $day($calendar[1], '2027-03-13')['numAvail'], 'overbooked: 3 bookings on 2 units');
        self::assertSame(['noCheckInOrCheckOut', 3], [$day($calendar[1], '2027-03-20')['override'], $day($calendar[1], '2027-03-20')['minStay']]);
        self::assertSame(['blackout', 0], [$day($calendar[2], '2027-04-06')['override'], $day($calendar[2], '2027-04-06')['numAvail']]);

        self::assertCount(60, $prices[0]['data']);
        $soldOut = $prices[0]['data'][4];
        self::assertSame(['2027-03-05', '4/4', 'Booked'], [$soldOut['date'], $soldOut['multi_unit_occupancy'], $soldOut['booking_status']]);
        self::assertSame('2/2', $prices[1]['data'][0]['multi_unit_occupancy']);
        self::assertSame('LISTING_NOT_PRESENT', $prices[2]['error_status']);
    }

    public function testTheScenarioCanBeMovedInTime(): void
    {
        $file = Fixtures::path('api/scenario.json');
        $original = Scenario::load($file);
        $moved = Scenario::load($file, '2026-11-12');

        self::assertSame('2027-03-05', $original->bookings()[0]['arrival']);
        self::assertSame('2026-11-16', $moved->bookings()[0]['arrival']);
        self::assertSame('2026-09-23T14:22:05Z', $moved->bookings()[0]['bookingTime']);
        self::assertSame($original->available($original->rooms()[1], '2027-03-13'), $moved->available($moved->rooms()[1], '2026-11-24'));
    }

    public function testTheInventedWorldHoldsNoOwnerOrGuestData(): void
    {
        $terms = Fixtures::json('owner-terms.json')['terms'];
        $pattern = '/(?<![\p{L}\p{N}_])(' . implode('|', array_map(static fn (string $term): string => preg_quote($term, '/'), $terms)) . ')(?![\p{L}\p{N}_])/iu';
        $root = dirname(__DIR__, 2);
        $files = array_merge(glob($root . '/fake-apis/*.php') ?: [], glob($root . '/fixtures/api/*') ?: [], glob($root . '/Fakes/*.php') ?: []);

        self::assertGreaterThan(8, count($files));
        foreach ($files as $file) {
            self::assertSame(0, preg_match($pattern, (string) file_get_contents($file), $match), basename($file) . ': ' . ($match[1] ?? ''));
        }

        $scenario = Fixtures::json('api/scenario.json');
        foreach ($scenario['bookings'] as $booking) {
            self::assertSame([], array_intersect(array_keys($booking), ['firstName', 'lastName', 'email', 'phone', 'mobile', 'address', 'comments', 'notes', 'guests']), 'guest fields are synthesised by the simulator, never stored');
        }
    }
}
