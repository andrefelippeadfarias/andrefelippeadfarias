<?php

declare(strict_types=1);

namespace Ritmo\Domain\Write\Hold;

use DateTimeImmutable;

/**
 * One wave of cancellations of a channel: the bookings by their PMS id (never guest data), the
 * first and last cancellation, and the room nights they had taken.
 */
final class Wave
{
    /**
     * @param list<string>              $bookingRefs
     * @param array<string, list<string>> $nights room code => "YYYY-MM-DD" nights of the cancelled bookings
     */
    public function __construct(
        public readonly string $channel,
        public readonly array $bookingRefs,
        public readonly DateTimeImmutable $firstAt,
        public readonly DateTimeImmutable $lastAt,
        public readonly array $nights,
    ) {
    }
}
