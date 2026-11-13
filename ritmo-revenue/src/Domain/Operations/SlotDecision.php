<?php

declare(strict_types=1);

namespace Ritmo\Domain\Operations;

use DateTimeImmutable;

/**
 * What a tick does with the due slots of one property: the slot to run (if any) and how, and the
 * slots it closes without a run.
 */
final class SlotDecision
{
    /**
     * @param list<array{0: Slot, 1: SlotStatus}>                     $closures   slots closed without a run
     * @param array{0: DateTimeImmutable, 1: DateTimeImmutable}|null $missedGap  first and last instant of the scheduled slots of past days that never ran
     */
    public function __construct(
        public readonly ?Slot $run,
        public readonly ?RunKind $runKind,
        public readonly bool $promoted,
        public readonly int $delaySeconds,
        public readonly bool $late,
        public readonly array $closures,
        public readonly ?array $missedGap,
    ) {
    }
}
