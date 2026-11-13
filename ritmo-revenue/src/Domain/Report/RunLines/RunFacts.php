<?php

declare(strict_types=1);

namespace Ritmo\Domain\Report\RunLines;

/**
 * What a run did, as the report stage reads it from the run summary (watch, validate, write,
 * verify), in plain numbers and reason codes.
 */
final class RunFacts
{
    /**
     * @param array<string, int> $pmsSkipped reason code => PMS changes skipped for it
     */
    public function __construct(
        public readonly bool $light = false,
        public readonly int $lightNights = 0,
        public readonly bool $shadow = true,
        public readonly bool $syncNow = false,
        public readonly int $securityFindings = 0,
        public readonly int $overbooked = 0,
        public readonly bool $waveHold = false,
        public readonly int $wouldWrite = 0,
        public readonly int $pmsWouldWrite = 0,
        public readonly bool $proposed = false,
        public readonly int $entries = 0,
        public readonly ?string $refused = null,
        public readonly int $callsSent = 0,
        public readonly int $confirmed = 0,
        public readonly int $pmsConfirmed = 0,
        public readonly ?string $stop = null,
        public readonly int $held = 0,
        public readonly int $rejected = 0,
        public readonly int $pmsRejected = 0,
        public readonly int $overdue = 0,
        public readonly ?int $marketAgeDays = null,
        public readonly array $pmsSkipped = [],
        public readonly int $mergeConflicts = 0,
    ) {
    }
}
