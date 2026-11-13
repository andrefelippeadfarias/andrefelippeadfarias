<?php

declare(strict_types=1);

namespace Ritmo\Application\Write\Calendar;

use Ritmo\Application\Write\Schedule\CalendarChange;
use Ritmo\Domain\Stay\Write\PmsCheckResult;
use Ritmo\Domain\Stay\Write\PmsChangeEntry;

/**
 * The PMS side of a run after the check: the calendar changes for the gate, the check's verdict
 * (refusals with their codes, the cap) and the counts of the run report.
 */
final class PmsWriteBatch
{
    /**
     * @param list<CalendarChange>  $changes
     * @param list<PmsChangeEntry>  $frozenOut changes left out on frozen nights ("would fix")
     */
    public function __construct(
        public readonly array $changes,
        public readonly PmsCheckResult $check,
        public readonly array $frozenOut,
        public readonly int $mergeConflicts,
    ) {
    }

    /**
     * The alert codes the run raises for its PMS side.
     *
     * @return list<string>
     */
    public function alerts(): array
    {
        $alerts = [];
        if ($this->check->capped) {
            $alerts[] = 'pms_changes_capped';
        }
        if ($this->check->refusedWith('pms_reverted_by_push') !== []) {
            $alerts[] = 'stay_reverted_by_push';
        }

        return $alerts;
    }
}
