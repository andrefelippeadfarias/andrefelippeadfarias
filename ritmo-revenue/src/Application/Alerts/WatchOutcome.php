<?php

declare(strict_types=1);

namespace Ritmo\Application\Alerts;

use Ritmo\Domain\Alerts\BelowExpectedFinding;
use Ritmo\Domain\Alerts\ClosedNight;
use Ritmo\Domain\Alerts\SecurityFinding;
use Ritmo\Domain\Report\OverbookedNight;
use Ritmo\Domain\Write\Hold\Wave;

/**
 * What the watch found in one run.
 */
final class WatchOutcome
{
    /**
     * @param list<SecurityFinding>      $security
     * @param list<BelowExpectedFinding> $belowExpected
     * @param list<OverbookedNight>      $overbooked
     * @param list<ClosedNight>          $closed
     * @param array<string, true>        $blocked      PlanItem keys of the closed nights (RunInputs::$blocked)
     * @param list<Wave>                 $waves        cancellation waves (they start the hold of price cuts)
     * @param list<string>               $originsSeen  origins of the changed bookings, while the known list is not confirmed
     */
    public function __construct(
        public readonly array $security,
        public readonly array $belowExpected,
        public readonly array $overbooked,
        public readonly array $closed,
        public readonly array $blocked,
        public readonly array $waves,
        public readonly array $originsSeen,
    ) {
    }
}
