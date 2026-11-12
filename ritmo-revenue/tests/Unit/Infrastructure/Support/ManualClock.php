<?php

declare(strict_types=1);

namespace Ritmo\Tests\Unit\Infrastructure\Support;

use DateTimeImmutable;
use DateTimeZone;
use Ritmo\Domain\Support\Clock;
use Ritmo\Domain\Support\Days;

/**
 * A clock the test moves by hand, so waits and expirations happen without real time passing.
 */
final class ManualClock implements Clock
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

    public function advance(float $seconds): void
    {
        $microseconds = (int) round($seconds * 1_000_000);
        $this->now = $this->now->modify(sprintf('%+d microseconds', $microseconds));
    }
}
