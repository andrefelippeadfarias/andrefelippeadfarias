<?php

declare(strict_types=1);

namespace Ritmo\Infrastructure\Http;

use InvalidArgumentException;

/**
 * "At most $max calls in any $windowSeconds seconds."
 */
final class RateLimit
{
    public function __construct(
        public readonly int $max,
        public readonly int $windowSeconds,
    ) {
        if ($max < 1 || $windowSeconds < 1) {
            throw new InvalidArgumentException('A rate limit needs at least one call and a window of at least one second.');
        }
    }

    public static function perMinute(int $max): self
    {
        return new self($max, 60);
    }

    public static function perHour(int $max): self
    {
        return new self($max, 3600);
    }
}
