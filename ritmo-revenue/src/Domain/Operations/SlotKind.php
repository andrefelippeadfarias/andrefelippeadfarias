<?php

declare(strict_types=1);

namespace Ritmo\Domain\Operations;

/**
 * What a scheduled slot is for: the configured full and light runs, the one retry of a run that
 * failed for a connector reason, and the run that writes a plan a person approved.
 */
enum SlotKind: string
{
    case Full = 'full';
    case Light = 'light';
    case Retry = 'retry';
    case ApprovedWrite = 'approved_write';

    /**
     * True for the slots that come from the schedule and its retries (not from an approval).
     */
    public function isScheduled(): bool
    {
        return $this !== self::ApprovedWrite;
    }
}
