<?php

declare(strict_types=1);

namespace Ritmo\Domain\Operations;

/**
 * Where a slot occurrence stands. Every slot ends in one of the final statuses: none disappears.
 */
enum SlotStatus: string
{
    case Due = 'due';
    case Running = 'running';
    case Completed = 'completed';
    case Failed = 'failed';
    case Stopped = 'stopped';
    /** A run that started at or after the slot and completed already read what the slot would read. */
    case SkippedCovered = 'skipped_covered';
    /** A later slot of the same day ran instead (one run covers every missed light slot). */
    case SkippedCoalesced = 'skipped_coalesced';
    /** The slot belongs to a local day that is over: nothing of that day runs any more. */
    case SkippedMissedDay = 'skipped_missed_day';
    case SkippedPaused = 'skipped_paused';
    /** The emergency switch stops reads too (operations setting stop_reads_under_emergency). */
    case SkippedBlocked = 'skipped_blocked';

    public function isFinal(): bool
    {
        return $this !== self::Due && $this !== self::Running;
    }
}
