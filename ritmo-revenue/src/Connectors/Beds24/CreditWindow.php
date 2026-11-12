<?php

declare(strict_types=1);

namespace Ritmo\Connectors\Beds24;

use Ritmo\Contracts\Exception\RateLimited;
use Ritmo\Contracts\HttpResponse;
use Ritmo\Domain\Support\Clock;

/**
 * The five-minute call credit of the Beds24 API, as its response headers report it.
 *
 * Every response says how much credit is left, how long until the window resets and what the
 * request cost. When the credit left would not pay for another request, the connector stops
 * with RateLimited instead of running into a 429. It never waits: waiting is a decision of
 * whoever runs the batch.
 */
final class CreditWindow
{
    /** Header names in both spellings seen in use: the live API first, the published spec second. */
    private const REMAINING = ['X-Five-Min-Limit-Remaining', 'X-FiveMinCreditLimit-Remaining'];

    private const RESETS_IN = ['X-Five-Min-Limit-Resets-In', 'X-FiveMinCreditLimit-ResetsIn'];

    private const COST = ['X-Request-Cost', 'X-RequestCost'];

    private ?float $remaining = null;

    private int $resetsAt = 0;

    private float $lastCost = 1.0;

    /**
     * @param float $minimumCredit credit below which no request is sent; 0 turns the check off
     */
    public function __construct(
        private readonly Clock $clock,
        private readonly float $minimumCredit,
    ) {
    }

    /**
     * Remembers what a response reports. A response without both the credit left and the reset
     * time changes nothing.
     */
    public function note(HttpResponse $response): void
    {
        $remaining = self::number($response, self::REMAINING);
        $resetsIn = self::number($response, self::RESETS_IN);
        if ($remaining === null || $resetsIn === null) {
            return;
        }

        $this->remaining = $remaining;
        $this->resetsAt = $this->now() + (int) ceil(max(0.0, $resetsIn));
        $this->lastCost = self::number($response, self::COST) ?? 1.0;
    }

    /**
     * @throws RateLimited when the last response left too little credit and the window is still open
     */
    public function assertEnough(): void
    {
        if ($this->minimumCredit <= 0 || $this->remaining === null) {
            return;
        }

        $untilReset = $this->resetsAt - $this->now();
        if ($untilReset <= 0) {
            $this->remaining = null;

            return;
        }
        if ($this->remaining >= max($this->minimumCredit, $this->lastCost)) {
            return;
        }

        throw new RateLimited(
            sprintf('Beds24 call credit is low (%s left); no request was sent.', self::plain($this->remaining)),
            Beds24Connector::ID,
            $untilReset,
            null
        );
    }

    /**
     * Seconds until the window resets according to a response, when it says so.
     */
    public static function resetsIn(HttpResponse $response): ?int
    {
        $resetsIn = self::number($response, self::RESETS_IN);

        return $resetsIn === null ? null : (int) ceil(max(0.0, $resetsIn));
    }

    /**
     * Credit left according to the last response that reported it; null when unknown.
     */
    public function remaining(): ?float
    {
        return $this->remaining;
    }

    private function now(): int
    {
        return $this->clock->now()->getTimestamp();
    }

    /**
     * @param list<string> $names
     */
    private static function number(HttpResponse $response, array $names): ?float
    {
        foreach ($names as $name) {
            $value = $response->header($name);
            if ($value !== null && is_numeric(trim($value))) {
                return (float) trim($value);
            }
        }

        return null;
    }

    private static function plain(float $number): string
    {
        return rtrim(rtrim(sprintf('%.2F', $number), '0'), '.');
    }
}
