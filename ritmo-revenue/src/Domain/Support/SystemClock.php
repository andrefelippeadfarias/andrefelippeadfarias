<?php

declare(strict_types=1);

namespace Ritmo\Domain\Support;

use DateTimeImmutable;
use DateTimeZone;

/**
 * Clock backed by the system time. The only class in src/Domain allowed to read it.
 */
final class SystemClock implements Clock
{
    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }

    public function today(string $timezone): DateTimeImmutable
    {
        return Days::localDate($this->now(), $timezone);
    }
}
