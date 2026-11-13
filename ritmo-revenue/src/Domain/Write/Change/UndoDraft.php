<?php

declare(strict_types=1);

namespace Ritmo\Domain\Write\Change;

use Ritmo\Domain\Plan\Plan;

/**
 * An undo as a plan: the pricing entries go through the Validator and the write gate like any
 * plan (with a human approval), the PMS restores alongside. Nothing here is written.
 */
final class UndoDraft
{
    /**
     * @param list<PmsRestore> $pmsRestores
     * @param list<UndoSkip>   $skipped
     * @param list<int>        $undoOf the change records the draft undoes
     * @param list<string>     $notes  what the undo approximates (an amount restored as the nearest whole percentage)
     */
    public function __construct(
        public readonly Plan $plan,
        public readonly array $pmsRestores,
        public readonly array $skipped,
        public readonly array $undoOf,
        public readonly array $notes = [],
    ) {
    }

    public function isEmpty(): bool
    {
        return $this->plan->items === [] && $this->plan->deletions === [] && $this->plan->listingChanges === [] && $this->pmsRestores === [];
    }
}
