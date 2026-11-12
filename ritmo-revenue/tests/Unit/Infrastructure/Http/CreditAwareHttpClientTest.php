<?php

declare(strict_types=1);

namespace Ritmo\Tests\Unit\Infrastructure\Http;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Ritmo\Contracts\HttpResponse;
use Ritmo\Infrastructure\Http\CreditAwareHttpClient;
use Ritmo\Infrastructure\Http\CreditHeaders;
use Ritmo\Tests\Unit\Infrastructure\Support\ClockSleeper;
use Ritmo\Tests\Unit\Infrastructure\Support\ManualClock;
use Ritmo\Tests\Unit\Infrastructure\Support\ScriptedHttpClient;

final class CreditAwareHttpClientTest extends TestCase
{
    private const URL = 'https://api.pms.example/v2/bookings';

    private ManualClock $clock;

    private ClockSleeper $sleeper;

    protected function setUp(): void
    {
        $this->clock = new ManualClock('2026-11-11T12:00:00Z');
        $this->sleeper = new ClockSleeper($this->clock);
    }

    public function testWithCreditLeftNothingWaits(): void
    {
        $inner = new ScriptedHttpClient($this->response(200, 80, 240), $this->response(200, 79, 239));
        $client = $this->client($inner);

        self::assertSame(200, $client->request('GET', self::URL)->status());
        self::assertSame(200, $client->request('GET', self::URL, ['token' => 'abc'], null, 60)->status());

        self::assertSame([], $this->sleeper->sleeps);
        self::assertSame([], $client->waits());
        self::assertSame(['token' => 'abc'], $inner->requests[1]['headers']);
        self::assertSame(60, $inner->requests[1]['timeout']);
    }

    public function testLowCreditWaitsForTheWindowToResetBeforeTheNextRequest(): void
    {
        $inner = new ScriptedHttpClient($this->response(200, 3, 120), $this->response(200, 100, 300), $this->response(200, 99, 299));
        $client = $this->client($inner);

        $client->request('GET', self::URL);
        self::assertSame([], $this->sleeper->sleeps, 'the wait comes before the next request, not after this one');

        $this->clock->advance(20);
        $client->request('GET', self::URL);

        self::assertSame([101.0], $this->sleeper->sleeps, '100 s left in the window plus one of margin');
        self::assertSame([101.0], $client->waits());

        $client->request('GET', self::URL);
        self::assertSame([101.0], $this->sleeper->sleeps, 'the fresh window has credit again');
    }

    public function testCreditBelowTheCostOfTheLastRequestAlsoWaits(): void
    {
        $inner = new ScriptedHttpClient($this->response(200, 8, 50, 10), $this->response(200, 100, 300));
        $client = $this->client($inner);

        $client->request('GET', self::URL);
        $client->request('GET', self::URL);

        self::assertSame([51.0], $this->sleeper->sleeps, '8 credits are above the minimum of 5 but below the last cost of 10');
    }

    public function testAWindowThatAlreadyTurnedNeedsNoWait(): void
    {
        $inner = new ScriptedHttpClient($this->response(200, 0, 30), $this->response(200, 100, 300));
        $client = $this->client($inner);

        $client->request('GET', self::URL);
        $this->clock->advance(31);
        $client->request('GET', self::URL);

        self::assertSame([], $this->sleeper->sleeps);
    }

    public function testBothSpellingsOfTheHeadersAreUnderstood(): void
    {
        $documented = new HttpResponse(200, ['X-FiveMinCreditLimit-Remaining' => '1', 'X-FiveMinCreditLimit-ResetsIn' => '40', 'X-RequestCost' => '1']);
        $inner = new ScriptedHttpClient($documented, $this->response(200, 100, 300));
        $client = $this->client($inner);

        $client->request('GET', self::URL);
        $client->request('GET', self::URL);

        self::assertSame([41.0], $this->sleeper->sleeps);
    }

    public function testAResponseWithoutBothHeadersChangesNothing(): void
    {
        $inner = new ScriptedHttpClient(
            $this->response(200, 2, 100),
            new HttpResponse(200, ['X-Five-Min-Limit-Remaining' => '90']),
            new HttpResponse(200, ['X-Five-Min-Limit-Remaining' => 'plenty', 'X-Five-Min-Limit-Resets-In' => 'soon']),
            $this->response(200, 100, 300)
        );
        $client = $this->client($inner, 5.0, 310.0);

        $client->request('GET', self::URL);
        $client->request('GET', self::URL);   // waits 101 s for the low credit of the first response
        $client->request('GET', self::URL);   // the incomplete response taught nothing
        $client->request('GET', self::URL);   // nor did the one that is not numeric

        self::assertSame([101.0], $this->sleeper->sleeps);
    }

    public function testA429ToAGetWaitsForTheAnnouncedResetAndTriesOnce(): void
    {
        $inner = new ScriptedHttpClient($this->response(429, 0, 77), $this->response(200, 100, 300));
        $client = $this->client($inner);

        $response = $client->request('GET', self::URL);

        self::assertSame(200, $response->status());
        self::assertSame([78.0], $this->sleeper->sleeps);
        self::assertCount(2, $inner->requests);
        self::assertSame($inner->requests[0], $inner->requests[1], 'the same request is sent again');
    }

    public function testASecond429IsReturnedToTheCaller(): void
    {
        $inner = new ScriptedHttpClient($this->response(429, 0, 10), $this->response(429, 0, 300), $this->response(200, 100, 300));
        $client = $this->client($inner);

        self::assertSame(429, $client->request('GET', self::URL)->status());
        self::assertCount(2, $inner->requests, 'one retry only');
        self::assertSame([11.0], $this->sleeper->sleeps);
    }

    public function testA429WithoutAResetWaitsTheDefault(): void
    {
        $inner = new ScriptedHttpClient(new HttpResponse(429), new HttpResponse(200));
        $client = $this->client($inner);

        self::assertSame(200, $client->request('GET', self::URL)->status());
        self::assertSame([61.0], $this->sleeper->sleeps);
    }

    public function testA429ToAnotherMethodIsNeverRepeated(): void
    {
        $inner = new ScriptedHttpClient($this->response(429, 0, 10), $this->response(200, 100, 300));
        $client = $this->client($inner);

        self::assertSame(429, $client->request('POST', self::URL, [], '{}')->status());
        self::assertCount(1, $inner->requests);
        self::assertSame([], $this->sleeper->sleeps);
    }

    public function testNoWaitIsLongerThanTheMaximum(): void
    {
        $inner = new ScriptedHttpClient($this->response(200, 0, 900), $this->response(429, 0, 900), $this->response(200, 100, 300));
        $client = $this->client($inner, 5.0, 310.0);

        $client->request('GET', self::URL);
        $client->request('GET', self::URL);

        self::assertSame([310.0, 310.0], $client->waits());
        self::assertSame(620.0, array_sum($this->sleeper->sleeps));
    }

    public function testWithAZeroMaximumItNeverSleeps(): void
    {
        $inner = new ScriptedHttpClient($this->response(200, 0, 100), $this->response(429, 0, 100), $this->response(429, 0, 100));
        $client = $this->client($inner, 5.0, 0.0);

        $client->request('GET', self::URL);

        self::assertSame(429, $client->request('GET', self::URL)->status());
        self::assertSame([], $this->sleeper->sleeps);
        self::assertSame([], $client->waits());
    }

    public function testTheHeaderNamesAreConfigurable(): void
    {
        $inner = new ScriptedHttpClient(new HttpResponse(200, ['RateLimit-Remaining' => '0', 'RateLimit-Reset' => '9']), new HttpResponse(200));
        $client = new CreditAwareHttpClient($inner, $this->clock, $this->sleeper, new CreditHeaders(['RateLimit-Remaining'], ['RateLimit-Reset']));

        $client->request('GET', self::URL);
        $client->request('GET', self::URL);

        self::assertSame([10.0], $this->sleeper->sleeps);

        $this->expectException(InvalidArgumentException::class);
        new CreditHeaders([], ['RateLimit-Reset']);
    }

    private function client(ScriptedHttpClient $inner, float $minimum = 5.0, float $maxWait = 310.0): CreditAwareHttpClient
    {
        return new CreditAwareHttpClient($inner, $this->clock, $this->sleeper, CreditHeaders::fiveMinuteWindow(), $minimum, $maxWait);
    }

    private function response(int $status, int $remaining, int $resetsIn, int $cost = 1): HttpResponse
    {
        return new HttpResponse($status, [
            'X-Five-Min-Limit-Remaining' => (string) $remaining,
            'X-Five-Min-Limit-Resets-In' => (string) $resetsIn,
            'X-Request-Cost' => (string) $cost,
        ]);
    }
}
