<?php

declare(strict_types=1);

namespace Ritmo\Domain\Stay\Write;

use Ritmo\Domain\Plan\PlanItem;

/**
 * The stay writes of a run: the PMS changes in write order (paired or held, unpaired ones still
 * listed for the check to refuse), the plan items with the stay values merged in, the room nights
 * where a stay value replaced a price item's (report line stay_merge_conflict) and what the stay
 * rules ask for (the check's re-check).
 */
final class StayWritePlan
{
    /**
     * @param list<PmsChangeEntry> $pms
     * @param list<PlanItem>       $items
     * @param list<string>         $conflicts   plan item keys
     * @param array<string, true>  $ruleTargets PmsChangeEntry::identity() of every change the rules ask for
     * @param list<PmsChangeEntry> $frozenOut   changes left out on frozen nights ("would fix")
     */
    public function __construct(
        public readonly array $pms,
        public readonly array $items,
        public readonly array $conflicts,
        public readonly array $ruleTargets,
        public readonly array $frozenOut,
    ) {
    }

    /**
     * Writes off: the price items untouched, no PMS change.
     *
     * @param list<PlanItem> $items
     */
    public static function off(array $items): self
    {
        return new self([], $items, [], [], []);
    }
}
