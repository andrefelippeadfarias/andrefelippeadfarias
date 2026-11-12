<?php

declare(strict_types=1);

namespace Ritmo\Tests\Unit\Infrastructure\Http;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Ritmo\Contracts\Exception\RateLimited;
use Ritmo\Contracts\HttpResponse;
use Ritmo\Infrastructure\Http\RateLimit;
use Ritmo\Infrastructure\Http\RateLimiter;
use Ritmo\Infrastructure\Http\ThrottledHttpClient;
use Ritmo\Tests\Unit\Infrastructure\Support\ClockSleeper;
use Ritmo\Tests\Unit\Infrastructure\Support\ManualClock;
use Ritmo\Tests\Unit\Infrastructure\Support\ScriptedHttpClient;

final class RateLimiterTest extends TestCase
{
    private ManualClock $clock;

    private ClockSleeper $sleeper;

    protected function setUp(): void
    {
        $this->clock = new ManualClock('2026-11-11T12:00:00Z');
        $this->sleeper = new ClockSleeper($this->clock);
    }

    public function testCallsUnderTheLimitNeverWait(): void
    {
        $limiter = $this->limiter([RateLimit::perMinute(3)]);

        self::assertSame(0.0, $limiter->acquire('api'));
        self::assertSame(0.0, $limiter->acquire('api'));
        self::assertSame(0.0, $limiter->acquire('api'));

        self::assertSame([], $this->sleeper->sleeps);
        self::assertSame(3, $limiter->used('api', 60));
    }

    public function testTheWindowSlidesWithTheOldestCall(): void
    {
        $limiter = $this->limiter([RateLimit::perMinute(3)]);
        $limiter->acquire('api');           // 12:00:00
        $this->clock->advance(10);
        $limiter->acquire('api');           // 12:00:10
        $this->clock->advance(10);
        $limiter->acquire('api');           // 12:00:20
        $this->clock->advance(5);           // 12:00:25

        self::assertSame(35.0, $limiter->waitTime('api'), 'until the first call is one minute old');
        self::assertFalse($limiter->tryAcquire('api'));
        self::assertSame(3, $limiter->used('api', 60), 'a refused call is not counted');

        self::assertSame(35.0, $limiter->acquire('api'));   // waits until 12:01:00
        self::assertSame([35.0], $this->sleeper->sleeps);
        self::assertSame('12:01:00', $this->clock->now()->format('H:i:s'));

        self::assertSame(10.0, $limiter->waitTime('api'), 'now the 12:00:10 call holds the window');
        $this->clock->advance(10);
        self::assertTrue($limiter->tryAcquire('api'));
    }

    public function testACallExactlyOneWindowOldNoLongerCounts(): void
    {
        $limiter = $this->limiter([new RateLimit(1, 60)]);
        $limiter->acquire('api');

        $this->clock->advance(59.999);
        self::assertGreaterThan(0.0, $limiter->waitTime('api'));

        $this->clock->advance(0.001);
        self::assertSame(0.0, $limiter->waitTime('api'));
        self::assertSame(0, $limiter->used('api', 60));
    }

    public function testEveryLimitAppliesAtOnce(): void
    {
        $limiter = $this->limiter([new RateLimit(2, 10), new RateLimit(3, 100)], 1000.0);
        $limiter->acquire('api');           // t=0
        $limiter->acquire('api');           // t=0

        self::assertSame(10.0, $limiter->acquire('api'), 'held by the short window');    // t=10
        self::assertSame(90.0, $limiter->acquire('api'), 'then by the long one');         // t=100
        self::assertSame([10.0, 90.0], $this->sleeper->sleeps);
        self::assertSame(2, $limiter->used('api', 100), 'the two calls of t=0 left the long window');
    }

    public function testSixtyPerMinuteAndAThousandPerHour(): void
    {
        $limiter = $this->limiter([RateLimit::perMinute(60), RateLimit::perHour(1000)], 4000.0);

        for ($call = 0; $call < 1000; ++$call) {
            $limiter->acquire('api');
        }

        self::assertCount(16, $this->sleeper->sleeps, 'a pause after each burst of sixty');
        self::assertSame(60.0, $this->sleeper->sleeps[0]);
        self::assertSame(960.0, array_sum($this->sleeper->sleeps));
        self::assertSame(1000, $limiter->used('api', 3600));

        self::assertSame(2640.0, $limiter->waitTime('api'), 'the hour is full: the next call waits for the first burst to turn one hour old');
        self::assertSame(2640.0, $limiter->acquire('api'));
        self::assertSame('13:00:00', $this->clock->now()->format('H:i:s'));
    }

    public function testKeysAreCountedApart(): void
    {
        $limiter = $this->limiter([RateLimit::perMinute(1)]);
        $limiter->acquire('pricing');

        self::assertSame(0.0, $limiter->acquire('pms'));
        self::assertFalse($limiter->tryAcquire('pricing'));
        self::assertSame(0, $limiter->used('other', 60));
    }

    public function testAWaitLongerThanTheMaximumGivesUpWithoutCounting(): void
    {
        $limiter = $this->limiter([RateLimit::perHour(1)], 30.0);
        $limiter->acquire('api');
        $this->clock->advance(600);

        try {
            $limiter->acquire('api');
            self::fail('The limiter must give up.');
        } catch (RateLimited $limited) {
            self::assertSame(3000, $limited->retryAfterSeconds());
            self::assertNull($limited->httpStatus(), 'no request was sent');
            self::assertStringContainsString('"api"', $limited->getMessage());
        }

        self::assertSame([], $this->sleeper->sleeps);
        self::assertSame(1, $limiter->used('api', 3600));
    }

    public function testASleeperThatDoesNotLetTimePassCannotLoopForever(): void
    {
        $stuck = new class () implements \Ritmo\Infrastructure\Support\Sleeper {
            public int $calls = 0;

            public function sleep(float $seconds): void
            {
                ++$this->calls;
            }
        };
        $limiter = new RateLimiter($this->clock, $stuck, [RateLimit::perMinute(1)], 1.0e9);
        $limiter->acquire('api');

        try {
            $limiter->acquire('api');
            self::fail('The limiter must give up.');
        } catch (RateLimited) {
            self::assertLessThanOrEqual(2, $stuck->calls);
        }
    }

    public function testLimitsAreValidated(): void
    {
        foreach ([
            static fn () => new RateLimit(0, 60),
            static fn () => new RateLimit(1, 0),
            fn () => new RateLimiter($this->clock, $this->sleeper, []),
        ] as $call) {
            try {
                $call();
                self::fail('The limit must be refused.');
            } catch (InvalidArgumentException) {
            }
        }
        $this->addToAssertionCount(3);
    }

    public function testAThrottledClientTakesItsTurnBeforeEveryRequest(): void
    {
        $inner = new ScriptedHttpClient(new HttpResponse(200, [], 'ok'));
        $client = new ThrottledHttpClient($inner, $this->limiter([new RateLimit(2, 10)]), 'pricing');

        $client->request('GET', 'https://api.pricing.example/v1/listings');
        $client->request('GET', 'https://api.pricing.example/v1/listings', ['X-A' => 'b'], null, 12);
        self::assertSame([], $this->sleeper->sleeps);

        self::assertSame('ok', $client->request('GET', 'https://api.pricing.example/v1/listings')->body());

        self::assertSame([10.0], $this->sleeper->sleeps);
        self::assertCount(3, $inner->requests);
        self::assertSame(['method' => 'GET', 'url' => 'https://api.pricing.example/v1/listings', 'headers' => ['X-A' => 'b'], 'body' => null, 'timeout' => 12], $inner->requests[1]);
    }

    /**
     * @param list<RateLimit> $limits
     */
    private function limiter(array $limits, float $maxWait = 120.0): RateLimiter
    {
        return new RateLimiter($this->clock, $this->sleeper, $limits, $maxWait);
    }
}
