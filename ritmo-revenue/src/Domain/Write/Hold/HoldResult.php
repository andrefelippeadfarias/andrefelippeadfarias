<?php

declare(strict_types=1);

namespace Ritmo\Domain\Write\Hold;

use Ritmo\Domain\Plan\Plan;

/**
 * The plan without the entries a cancellation wave holds, and those entries.
 */
final class HoldResult
{
    /**
     * @param list<HeldEntry> $held
     */
    public function __construct(
        public readonly Plan $plan,
        public readonly array $held,
    ) {
    }
}
