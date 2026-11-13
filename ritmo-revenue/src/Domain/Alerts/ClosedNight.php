<?php

declare(strict_types=1);

namespace Ritmo\Domain\Alerts;

use DateTimeImmutable;
use Ritmo\Domain\Support\Days;

/**
 * A room night the PMS keeps off sale beyond its bookings (a staff block, a closed room), or under
 * a blackout restriction.
 */
final class ClosedNight
{
    public readonly DateTimeImmutable $night;

    public function __construct(
        public readonly string $roomCode,
        DateTimeImmutable $night,
        public readonly int $offSale,
        public readonly int $booked,
        public readonly bool $blackout,
    ) {
        $this->night = Days::normalize($night);
    }
}
