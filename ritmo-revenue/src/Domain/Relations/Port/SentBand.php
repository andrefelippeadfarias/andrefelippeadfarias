<?php

declare(strict_types=1);

namespace Ritmo\Domain\Relations\Port;

use InvalidArgumentException;

/**
 * A price band of a room-night in the sent plan.
 */
final class SentBand
{
    public function __construct(
        public readonly float $low,
        public readonly float $high,
    ) {
        if ($low > $high) {
            throw new InvalidArgumentException('The low end of a band cannot be above its high end.');
        }
    }
}
