<?php

declare(strict_types=1);

namespace Ritmo\Tests\Fakes;

use DateTimeImmutable;
use DateTimeZone;
use Ritmo\Domain\Support\Clock;
use Ritmo\Domain\Support\Days;

/**
 * A clock a test moves by hand, so waits, expirations and changes of day happen without real
 * time passing (tokens, credit windows, locks, runs that resume on another day).
 */
final class MovableClock implements Clock
{
    private DateTimeImmutable $now;

    public function __construct(string $now = '2026-11-11T12:00:00Z')
    {
        $this->now = (new DateTimeImmutable($now))->setTimezone(new DateTimeZone('UTC'));
    }

    public function now(): DateTimeImmutable
    {
        return $this->now;
    }

    public function today(string $timezone): DateTimeImmutable
    {
        return Days::localDate($this->now, $timezone);
    }

    /**
     * Moves the clock by a number of seconds, to the microsecond (negative moves it back).
     */
    public function advance(float $seconds): void
    {
        // Whole seconds and the rest apart: PHP's date parser gets a move of a year or more in
        // microseconds wrong (400 days moved the clock 16 hours).
        $micro = (int) round($seconds * 1_000_000);
        $whole = intdiv($micro, 1_000_000);
        $rest = $micro - $whole * 1_000_000;
        $now = $this->now->modify(sprintf('%+d seconds', $whole));
        $this->now = $rest === 0 ? $now : $now->modify(sprintf('%+d microseconds', $rest));
    }
}
