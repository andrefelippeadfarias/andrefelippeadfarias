<?php

declare(strict_types=1);

namespace Ritmo\Domain\Support;

use DateTimeImmutable;

/**
 * The only source of time for the domain. Nothing under src/Domain may read the system clock
 * directly; everything receives a Clock.
 */
interface Clock
{
    /**
     * The current instant, always in UTC.
     */
    public function now(): DateTimeImmutable;

    /**
     * The current calendar date in the given IANA timezone, as a date-only value
     * (midnight UTC of that local date, the convention of {@see Days}).
     *
     * Example: at 2026-11-12T01:30Z, today('America/Sao_Paulo') is 2026-11-11T00:00:00+00:00.
     *
     * @throws \InvalidArgumentException when the timezone is not a valid IANA identifier
     */
    public function today(string $timezone): DateTimeImmutable;
}
