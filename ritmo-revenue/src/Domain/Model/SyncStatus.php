<?php

declare(strict_types=1);

namespace Ritmo\Domain\Model;

use DateTimeImmutable;

/**
 * State of the push of prices from the pricing system to the PMS for one listing.
 */
final class SyncStatus
{
    /**
     * @param bool                   $pushEnabled     true when the pricing system is sending prices to the PMS
     * @param DateTimeImmutable|null $lastPushedAt    instant in UTC of the last push; null when never or unknown
     * @param DateTimeImmutable|null $lastRefreshedAt instant in UTC of the last recalculation; null when unknown
     */
    public function __construct(
        public readonly bool $pushEnabled,
        public readonly ?DateTimeImmutable $lastPushedAt = null,
        public readonly ?DateTimeImmutable $lastRefreshedAt = null,
    ) {
    }
}
