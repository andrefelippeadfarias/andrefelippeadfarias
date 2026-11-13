<?php

declare(strict_types=1);

namespace Ritmo\Infrastructure\Http;

use Ritmo\Contracts\Exception\WriteBlocked;
use Ritmo\Contracts\HttpClient;
use Ritmo\Contracts\HttpResponse;
use Ritmo\Contracts\WriteHttpClient;
use Ritmo\Contracts\WriteToken;
use Ritmo\Domain\Support\Clock;
use Ritmo\Infrastructure\Support\Sleeper;

/**
 * Respects the call credit an API reports in its response headers.
 *
 * - Before a request: when the last response said the credit left is below the minimum (or
 *   below what the last request cost) and the window is still open, it waits for the window
 *   to reset instead of running into a 429.
 * - On a 429 to a GET: it waits for the reset the response announces (a default when it
 *   announces none) and tries once more. The second 429 is returned to the caller. Other
 *   methods are never repeated here.
 *
 * - A write waits for the credit the same way and is never repeated: a 429 goes back to the
 *   caller, who stops (a write is sent once).
 * No wait is longer than the maximum. Waits go through the Sleeper and are reported by
 * waits(), so a run can say how long it stood still.
 */
final class CreditAwareHttpClient implements WriteHttpClient
{
    private ?float $remaining = null;

    private float $resetsAt = 0.0;

    private float $lastCost = 1.0;

    /** @var list<float> */
    private array $waits = [];

    /**
     * @param float $minimumCredit     credit below which the client waits for the reset
     * @param float $maxWaitSeconds    longest single wait
     * @param float $defaultRetryAfter seconds to wait after a 429 that announces no reset
     */
    public function __construct(
        private readonly HttpClient $inner,
        private readonly Clock $clock,
        private readonly Sleeper $sleeper,
        private readonly CreditHeaders $headers,
        private readonly float $minimumCredit = 5.0,
        private readonly float $maxWaitSeconds = 310.0,
        private readonly float $defaultRetryAfter = 60.0,
    ) {
    }

    public function request(string $method, string $url, array $headers = [], ?string $body = null, int $timeout = 30): HttpResponse
    {
        $this->waitForCredit();
        $response = $this->inner->request($method, $url, $headers, $body, $timeout);
        $this->note($response);

        if ($response->status() !== 429 || strtoupper($method) !== 'GET') {
            return $response;
        }

        $announced = $this->number($response, $this->headers->resetsIn);
        $this->wait(($announced ?? $this->defaultRetryAfter) + 1);
        $this->remaining = null;

        $response = $this->inner->request($method, $url, $headers, $body, $timeout);
        $this->note($response);

        return $response;
    }

    public function write(WriteToken $token, string $method, string $url, array $headers = [], ?string $body = null, int $timeout = 30): HttpResponse
    {
        if (!$this->inner instanceof WriteHttpClient) {
            throw WriteBlocked::forRequest($method, $url, 'the HTTP client of this connector is read-only');
        }
        $this->waitForCredit();
        $response = $this->inner->write($token, $method, $url, $headers, $body, $timeout);
        $this->note($response);

        return $response;
    }

    /**
     * Seconds of every wait made by this client, in order.
     *
     * @return list<float>
     */
    public function waits(): array
    {
        return $this->waits;
    }

    private function waitForCredit(): void
    {
        if ($this->remaining === null) {
            return;
        }

        $untilReset = $this->resetsAt - $this->now();
        if ($untilReset > 0 && $this->remaining >= max($this->minimumCredit, $this->lastCost)) {
            return;
        }
        if ($untilReset > 0) {
            $this->wait($untilReset + 1);
        }
        // The window turned, or it was waited out: what the last response said is no longer true.
        $this->remaining = null;
    }

    /**
     * Remembers the credit a response reports. A response that does not report both the
     * credit left and the reset time changes nothing.
     */
    private function note(HttpResponse $response): void
    {
        $remaining = $this->number($response, $this->headers->remaining);
        $resetsIn = $this->number($response, $this->headers->resetsIn);
        if ($remaining === null || $resetsIn === null) {
            return;
        }

        $this->remaining = $remaining;
        $this->resetsAt = $this->now() + $resetsIn;
        $this->lastCost = $this->number($response, $this->headers->cost) ?? 1.0;
    }

    private function wait(float $seconds): void
    {
        $seconds = min($seconds, $this->maxWaitSeconds);
        if ($seconds > 0) {
            $this->waits[] = $seconds;
            $this->sleeper->sleep($seconds);
        }
    }

    /**
     * The first of the named headers that holds a number.
     *
     * @param list<string> $names
     */
    private function number(HttpResponse $response, array $names): ?float
    {
        foreach ($names as $name) {
            $value = $response->header($name);
            if ($value !== null && is_numeric(trim($value))) {
                return (float) trim($value);
            }
        }

        return null;
    }

    private function now(): float
    {
        return (float) $this->clock->now()->format('U.u');
    }
}
