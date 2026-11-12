<?php

declare(strict_types=1);

namespace Ritmo\Tests\Unit\Connectors\PriceLabs;

use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Ritmo\Connectors\PriceLabs\PriceLabsConfig;
use Ritmo\Connectors\PriceLabs\PriceLabsConnector;
use Ritmo\Contracts\Exception\AuthFailed;
use Ritmo\Contracts\Exception\ConnectorException;
use Ritmo\Contracts\Exception\NotAvailableInThisMilestone;
use Ritmo\Contracts\Exception\RateLimited;
use Ritmo\Contracts\HttpResponse;
use Ritmo\Contracts\WriteToken;
use Ritmo\Domain\Model\DateOverride;
use Ritmo\Domain\Model\ListingInfo;
use Ritmo\Domain\Model\PriceNight;
use Ritmo\Domain\Support\Days;
use Ritmo\Tests\Fakes\MemoryQuotaLedger;
use Ritmo\Tests\Fakes\MovableClock;
use Ritmo\Tests\Fakes\RecordingLogger;
use Ritmo\Tests\Fakes\ScriptedHttpClient;

final class PriceLabsConnectorTest extends TestCase
{
    private const KEY = 'sim-pricelabs-secret-key';

    private const LISTING = '901001___902001';

    private ScriptedHttpClient $http;

    private MemoryQuotaLedger $ledger;

    private MovableClock $clock;

    private RecordingLogger $logger;

    private PriceLabsConnector $connector;

    protected function setUp(): void
    {
        $this->http = new ScriptedHttpClient();
        $this->ledger = new MemoryQuotaLedger();
        $this->clock = new MovableClock('2027-03-01T10:00:00Z');
        $this->logger = new RecordingLogger();
        $this->connector = new PriceLabsConnector($this->http, new PriceLabsConfig(self::KEY, ' Beds24 '), $this->clock, $this->ledger, $this->logger);
    }

    protected function tearDown(): void
    {
        foreach ($this->http->requests() as $request) {
            $line = $request['method'] . ' ' . $request['path'];
            self::assertTrue($request['method'] === 'GET' || $line === 'POST /v1/listing_prices', 'only GET and the calendar read leave: ' . $line);
            self::assertSame(self::KEY, $request['headers']['X-API-Key']);
        }
        self::assertSame(0, $this->http->pending(), 'every scripted reply was used');
        self::assertStringNotContainsString(self::KEY, $this->logger->dump());
    }

    /**
     * @return array<string, mixed>
     */
    private static function listing(string $id, string $pms = 'beds24'): array
    {
        return ['id' => $id, 'pms' => $pms, 'name' => 'Room ' . $id, 'min' => 320, 'base' => 480, 'max' => 1600, 'currency' => 'BRL', 'push_enabled' => true, 'last_date_pushed' => '2027-02-28T09:10:00Z', 'last_refreshed_at' => '2027-02-28T21:30:00Z'];
    }

    private function directory(): HttpResponse
    {
        return ScriptedHttpClient::json(200, ['listings' => [self::listing(self::LISTING), self::listing('901001___902002', 'BEDS24'), self::listing('elsewhere-1', 'othersystem'), self::listing('')]]);
    }

    public function testTheConnectionTestCountsTheListingsOfThePms(): void
    {
        $this->http->queue($this->directory());

        $status = $this->connector->testConnection();

        self::assertSame('pricelabs', $this->connector->id());
        self::assertTrue($status->ok);
        self::assertSame('Connected to PriceLabs: 3 of 4 listings belong to the PMS "beds24".', $status->message);
        self::assertSame(['listings' => 3, 'account_listings' => 4, 'pms' => 'beds24'], $status->details);
        $request = $this->http->requests()[0];
        self::assertSame('https://api.pricelabs.co/v1/listings?skip_hidden=false', $request['url']);
        self::assertSame(['X-API-Key' => self::KEY, 'Accept' => 'application/json'], $request['headers']);
    }

    public function testAnInvalidKeyIsAnAuthFailureAndTheConnectionTestDoesNotThrow(): void
    {
        $invalid = ScriptedHttpClient::json(403, ['error' => ['code' => 'API_KEY_INVALID', 'message' => 'Invalid key ' . self::KEY]]);
        $this->http->queue($invalid, $invalid);

        $status = $this->connector->testConnection();
        self::assertFalse($status->ok);
        self::assertSame(['http_status' => 403], $status->details);
        self::assertStringContainsString('API_KEY_INVALID', $status->message);
        self::assertStringNotContainsString(self::KEY, $status->message);

        $this->expectException(AuthFailed::class);
        $this->connector->listListings();
    }

    public function testListingsAreThoseOfThePmsWithTheirValues(): void
    {
        $this->http->queue($this->directory());

        $listings = $this->connector->listListings();

        self::assertSame([self::LISTING, '901001___902002'], array_map(static fn (ListingInfo $l): string => $l->ref, $listings), 'other systems and items without an id are left out');
        self::assertSame([320.0, 480.0, 1600.0, 'BRL', true], [$listings[0]->min, $listings[0]->base, $listings[0]->max, $listings[0]->currency, $listings[0]->sync->pushEnabled]);
    }

    public function testADirectoryOutsideTheFormatIsAnError(): void
    {
        $this->http->queue(ScriptedHttpClient::json(200, ['data' => []]));

        $this->expectException(ConnectorException::class);
        $this->expectExceptionMessage('there is no list of listings');
        $this->connector->listListings();
    }

    public function testPricesAreReadByTheCalendarPostAndKeptInsideTheRange(): void
    {
        $this->http->queue(ScriptedHttpClient::json(200, [
            ['id' => 'other', 'data' => []],
            ['id' => self::LISTING, 'pms' => 'beds24', 'currency' => 'BRL', 'data' => [
                ['date' => '2027-03-06', 'price' => 600, 'user_price' => 600, 'multi_unit_occupancy' => '1/4', 'booking_status' => ''],
                ['date' => '2027-03-04', 'price' => 420, 'multi_unit_occupancy' => '0/4'],
                ['date' => '2027-03-05', 'price' => 600, 'multi_unit_occupancy' => '4/4', 'booking_status' => 'Booked'],
                ['date' => '2027-03-08', 'price' => 420],
                ['date' => '2027-03-05', 'price' => 610, 'multi_unit_occupancy' => '4/4'],
                ['price' => 99],
            ]],
        ]));

        $nights = $this->connector->readPrices(' ' . self::LISTING . ' ', Days::date('2027-03-04'), Days::date('2027-03-06'));

        self::assertSame(
            [['2027-03-04', 420.0, 0, 4], ['2027-03-05', 610.0, 4, 4], ['2027-03-06', 600.0, 1, 4]],
            array_map(static fn (PriceNight $n): array => [$n->date->format('Y-m-d'), $n->price, $n->sold, $n->total], $nights),
            'ascending, inside the range, the last row of a night wins'
        );
        self::assertCount(1, $this->logger->entries('warning'), 'the row without a date is counted, not hidden');

        $request = $this->http->requests()[0];
        self::assertSame('POST', $request['method']);
        self::assertSame('https://api.pricelabs.co/v1/listing_prices', $request['url']);
        self::assertSame('application/json', $request['headers']['Content-Type']);
        self::assertSame(
            ['listings' => [['id' => self::LISTING, 'pms' => 'beds24', 'dateFrom' => '2027-03-04', 'dateTo' => '2027-03-06']]],
            json_decode((string) $request['body'], true)
        );
    }

    public function testAListingWithoutCalendarIsAnError(): void
    {
        $this->http->queue(
            ScriptedHttpClient::json(200, [['id' => self::LISTING, 'pms' => 'beds24', 'error_status' => 'LISTING_TOGGLE_OFF']]),
            ScriptedHttpClient::json(200, [['id' => 'someone-else', 'data' => []]]),
            ScriptedHttpClient::json(200, [['id' => self::LISTING, 'data' => 'none']])
        );

        $messages = [];
        for ($attempt = 0; $attempt < 3; ++$attempt) {
            try {
                $this->connector->readPrices(self::LISTING, Days::date('2027-03-04'), Days::date('2027-03-06'));
            } catch (ConnectorException $e) {
                $messages[] = $e->getMessage();
            }
        }

        self::assertSame('PriceLabs has no calendar for the listing (LISTING_TOGGLE_OFF).', $messages[0]);
        self::assertStringContainsString('the listing is not in the answer', $messages[1]);
        self::assertStringContainsString('the listing has no list of nights', $messages[2]);
    }

    public function testOverridesAreReadByGetWithThePmsAndTheRange(): void
    {
        $this->http->queue(ScriptedHttpClient::json(200, ['overrides' => [
            ['date' => '2027-03-20', 'price' => '610', 'price_type' => 'fixed', 'reason' => ''],
            ['date' => '2027-03-05', 'min_price' => '25', 'min_price_type' => 'percent_base', 'max_price' => '80', 'max_price_type' => 'percent_base', 'reason' => 'Protection'],
            ['date' => '2027-04-30', 'min_price' => '5'],
            ['reason' => 'no date'],
        ]]));

        $overrides = $this->connector->readOverrides(self::LISTING, Days::date('2027-03-01'), Days::date('2027-03-31'));

        self::assertSame(['2027-03-05', '2027-03-20'], array_map(static fn (DateOverride $o): string => $o->date->format('Y-m-d'), $overrides));
        self::assertSame([25.0, 80.0], [$overrides[0]->minPercent, $overrides[0]->maxPercent]);
        self::assertSame([610.0, DateOverride::PRICE_FIXED], [$overrides[1]->price, $overrides[1]->priceType]);

        $request = $this->http->requests()[0];
        self::assertSame('GET', $request['method']);
        self::assertSame('/v1/listings/' . self::LISTING . '/overrides', $request['path']);
        self::assertSame(['pms' => ['beds24'], 'start_date' => ['2027-03-01'], 'end_date' => ['2027-03-31']], $request['query']);
    }

    public function testOverridesOutsideTheFormatAreAnError(): void
    {
        $this->http->queue(ScriptedHttpClient::json(200, ['data' => []]));

        $this->expectException(ConnectorException::class);
        $this->expectExceptionMessage('there is no list of overrides');
        $this->connector->readOverrides(self::LISTING, Days::date('2027-03-01'), Days::date('2027-03-31'));
    }

    public function testAMalformedListingIdIsRefusedBeforeAnythingIsSent(): void
    {
        foreach (['', '  ', '../listings', 'a/b', 'a?b=1', "a\nb", str_repeat('x', 129)] as $ref) {
            try {
                $this->connector->readOverrides($ref, Days::date('2027-03-01'), Days::date('2027-03-02'));
                self::fail('Refused: ' . json_encode($ref));
            } catch (InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
        self::assertSame([], $this->http->requests());
    }

    public function testA429IsRateLimitedWithTheRetryAfterOfTheApi(): void
    {
        $this->http->queue(ScriptedHttpClient::json(429, ['error' => 'RATE_LIMIT_EXCEEDED'], ['Retry-After' => '42']));

        try {
            $this->connector->readPrices(self::LISTING, Days::date('2027-03-04'), Days::date('2027-03-06'));
            self::fail('A 429 must end in RateLimited.');
        } catch (RateLimited $e) {
            self::assertSame(42, $e->retryAfterSeconds());
            self::assertSame(429, $e->httpStatus());
            self::assertStringContainsString('RATE_LIMIT_EXCEEDED', $e->getMessage());
        }
    }

    public function testErrorsAndTransportFailuresAreConnectorErrorsAndSpendTheBudget(): void
    {
        $this->http->queue(
            ScriptedHttpClient::json(500, ['error' => 'internal']),
            new ConnectorException('No response from the server.', 'http', null, true),
            new HttpResponse(200, [], '<html>maintenance</html>')
        );

        $failures = [];
        for ($attempt = 0; $attempt < 3; ++$attempt) {
            try {
                $this->connector->listListings();
            } catch (ConnectorException $e) {
                $failures[] = [$e->isRetryable(), $e->httpStatus()];
            }
        }

        self::assertSame([[true, 500], [true, null], [false, null]], $failures);
        self::assertSame([false, false, true], array_column($this->ledger->calls(), 'succeeded'), 'every request sent is counted, failed or lost');
    }

    public function testTheHourlyLimitHoldsAcrossRunsThroughTheLedger(): void
    {
        for ($call = 0; $call < PriceLabsConnector::REQUESTS_PER_HOUR; ++$call) {
            $this->ledger->record('pricelabs', 'request', '', true, $this->clock->now()->modify('-59 minutes'));
        }

        try {
            $this->connector->listListings();
            self::fail('A full hour must stop the request.');
        } catch (RateLimited $e) {
            self::assertNull($e->httpStatus());
        }
        self::assertSame([], $this->http->requests(), 'nothing was sent');

        $this->clock->advance(61);
        $this->http->queue($this->directory());
        self::assertCount(2, $this->connector->listListings());
        self::assertSame(60, PriceLabsConnector::REQUESTS_PER_MINUTE);
    }

    public function testEveryWriteAndTheRecalculationAreNotAvailableAndSendNothing(): void
    {
        $token = (new ReflectionClass(WriteToken::class))->newInstanceWithoutConstructor();
        $calls = [
            fn () => $this->connector->requestRecalculation($token, self::LISTING),
            fn () => $this->connector->writeOverrides($token, self::LISTING, [['date' => '2027-03-05', 'min_price' => '10']]),
            fn () => $this->connector->deleteOverrides($token, self::LISTING, ['2027-03-05']),
            fn () => $this->connector->updateListing($token, self::LISTING, ['min' => 300]),
        ];

        foreach ($calls as $call) {
            try {
                $call();
                self::fail('Writing must not be available in this milestone.');
            } catch (NotAvailableInThisMilestone) {
                self::addToAssertionCount(1);
            }
        }
        self::assertSame([], $this->http->requests());
        self::assertSame([], $this->ledger->calls());
    }

    public function testTheConfigurationHidesTheKeyAndChecksThePmsName(): void
    {
        $config = new PriceLabsConfig(self::KEY, 'Beds24');

        self::assertSame('beds24', $config->pmsName);
        self::assertStringNotContainsString(self::KEY, print_r($config, true));
        foreach ([['', 'beds24'], ['a key', 'beds24'], [self::KEY, ''], [self::KEY, 'beds 24']] as [$key, $pms]) {
            try {
                new PriceLabsConfig($key, $pms);
                self::fail('Refused: ' . json_encode([$key, $pms]));
            } catch (InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }

        $this->expectException(LogicException::class);
        serialize($config);
    }
}
