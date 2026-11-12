<?php

declare(strict_types=1);

namespace Ritmo\Infrastructure\Http;

use InvalidArgumentException;
use Ritmo\Contracts\Exception\RateLimited;
use Ritmo\Domain\Support\Clock;
use Ritmo\Infrastructure\Support\Sleeper;

/**
 * Keeps the calls made under a key (one API account, for example) within several limits at
 * once, each over a sliding window: "60 per minute and 1000 per hour".
 *
 * A call counts in a window while it is younger than the window. When a limit is reached,
 * acquire() waits until the oldest call that matters leaves the window, or gives up with
 * RateLimited when that would take longer than the maximum wait.
 *
 * The count lives in this object, so it covers one PHP process: the calls of one run. Quotas
 * that must hold across runs (a few calls per day) belong to the QuotaLedger.
 */
final class RateLimiter
{
    /** @var list<RateLimit> */
    private readonly array $limits;

    private readonly int $longestWindow;

    /** @var array<string, list<float>> call times per key, oldest first */
    private array $calls = [];

    /**
     * @param list<RateLimit> $limits         every limit that applies to a key
     * @param float           $maxWaitSeconds longest total wait acquire() accepts before giving up
     */
    public function __construct(
        private readonly Clock $clock,
        private readonly Sleeper $sleeper,
        array $limits,
        private readonly float $maxWaitSeconds = 120.0,
    ) {
        if ($limits === []) {
            throw new InvalidArgumentException('A rate limiter needs at least one limit.');
        }

        $this->limits = array_values($limits);
        $this->longestWindow = max(array_map(static fn (RateLimit $limit): int => $limit->windowSeconds, $this->limits));
    }

    /**
     * Seconds until a call under the key is allowed; 0.0 when it is allowed now.
     */
    public function waitTime(string $key): float
    {
        $now = $this->now();
        $calls = $this->recent($key, $now);
        $wait = 0.0;
        foreach ($this->limits as $limit) {
            $inWindow = array_values(array_filter($calls, static fn (float $at): bool => $at > $now - $limit->windowSeconds));
            if (count($inWindow) >= $limit->max) {
                // The window opens when the call that keeps it full gets old enough.
                $wait = max($wait, $inWindow[count($inWindow) - $limit->max] + $limit->windowSeconds - $now);
            }
        }

        // Rounded up to the millisecond, so that after waiting the call is clearly out of the window.
        return $wait <= 0.0 ? 0.0 : ceil($wait * 1000) / 1000;
    }

    /**
     * Counts a call when one is allowed now.
     *
     * @return bool false when a limit is reached; nothing is counted then
     */
    public function tryAcquire(string $key): bool
    {
        if ($this->waitTime($key) > 0.0) {
            return false;
        }

        $this->calls[$key][] = $this->now();

        return true;
    }

    /**
     * Waits until a call is allowed, then counts it.
     *
     * @return float seconds waited
     *
     * @throws RateLimited when the wait would exceed the maximum; nothing is counted then
     */
    public function acquire(string $key): float
    {
        $waited = 0.0;
        // Each wait clears the limit that asked for it, so there is at most one wait per limit.
        for ($round = 0; $round <= count($this->limits); ++$round) {
            $wait = $this->waitTime($key);
            if ($wait <= 0.0) {
                $this->calls[$key][] = $this->now();

                return $waited;
            }
            if ($waited + $wait > $this->maxWaitSeconds) {
                break;
            }
            $this->sleeper->sleep($wait);
            $waited += $wait;
        }

        $retryAfter = (int) ceil($this->waitTime($key));

        throw new RateLimited(sprintf('The call limit for "%s" is reached; the next call is allowed in about %d seconds.', $key, $retryAfter), '', $retryAfter, null);
    }

    /**
     * Calls counted under the key in the last $windowSeconds seconds.
     */
    public function used(string $key, int $windowSeconds): int
    {
        $now = $this->now();

        return count(array_filter($this->recent($key, $now), static fn (float $at): bool => $at > $now - $windowSeconds));
    }

    /**
     * The calls of the key that can still matter, after forgetting the ones older than the longest window.
     *
     * @return list<float>
     */
    private function recent(string $key, float $now): array
    {
        $calls = array_values(array_filter($this->calls[$key] ?? [], fn (float $at): bool => $at > $now - $this->longestWindow));
        if ($calls === []) {
            unset($this->calls[$key]);
        } else {
            $this->calls[$key] = $calls;
        }

        return $calls;
    }

    private function now(): float
    {
        return (float) $this->clock->now()->format('U.u');
    }
}
