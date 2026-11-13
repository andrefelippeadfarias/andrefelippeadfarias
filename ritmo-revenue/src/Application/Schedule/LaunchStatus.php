<?php

declare(strict_types=1);

namespace Ritmo\Application\Schedule;

use Ritmo\Domain\Operations\SlotStatus;

/**
 * Where a run stands after the launcher worked on it for one budget.
 */
enum LaunchStatus: string
{
    /** Still running: the next tick continues it from its next stage. */
    case Running = 'running';
    case Completed = 'completed';
    case Failed = 'failed';
    case Stopped = 'stopped';
    /** Another run of the property holds its lock: nothing started, the slot stays due. */
    case Busy = 'busy';

    public function slotStatus(): SlotStatus
    {
        return match ($this) {
            self::Running => SlotStatus::Running,
            self::Busy => SlotStatus::Due,
            self::Completed => SlotStatus::Completed,
            self::Failed => SlotStatus::Failed,
            self::Stopped => SlotStatus::Stopped,
        };
    }

    public function isFinal(): bool
    {
        return $this === self::Completed || $this === self::Failed || $this === self::Stopped;
    }
}
