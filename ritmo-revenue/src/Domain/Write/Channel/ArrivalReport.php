<?php

declare(strict_types=1);

namespace Ritmo\Domain\Write\Channel;

use DateTimeImmutable;

/**
 * Which written steps reached the channels, by the caller's keys.
 */
final class ArrivalReport
{
    /**
     * @param array<array-key, DateTimeImmutable> $delivered key => the push that carried the step
     * @param array<array-key, string>            $pending   key => why it does not count yet (ChannelArrival constants)
     * @param list<array-key>                     $overdue   pending keys written longer ago than the limit
     */
    public function __construct(
        public readonly array $delivered,
        public readonly array $pending,
        public readonly array $overdue,
    ) {
    }
}
