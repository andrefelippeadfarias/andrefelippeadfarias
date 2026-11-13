<?php

declare(strict_types=1);

namespace Ritmo\Domain\Operations;

use DateTimeImmutable;
use Ritmo\Domain\Support\Days;

/**
 * A room night whose sold-out state changed since the latest inventory read before this run:
 * it sold out, or it reopened.
 */
final class SoldOutFlip
{
    public readonly DateTimeImmutable $night;

    public function __construct(
        public readonly string $roomCode,
        DateTimeImmutable $night,
        public readonly bool $soldOut,
    ) {
        $this->night = Days::normalize($night);
    }
}
