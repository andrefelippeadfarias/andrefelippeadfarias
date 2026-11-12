<?php

declare(strict_types=1);

namespace Ritmo\Domain\Plan;

/**
 * A validated plan converted to percentages over the listing bases.
 */
final class PercentPlan
{
    /**
     * @param list<PercentItem> $items   what is left to write
     * @param list<PercentItem> $dropped items left out because the same percentages are already in force
     */
    public function __construct(
        public readonly ValidatedPlan $plan,
        public readonly array $items,
        public readonly array $dropped,
    ) {
    }
}
