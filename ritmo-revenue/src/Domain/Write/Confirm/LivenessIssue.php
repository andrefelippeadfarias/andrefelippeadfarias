<?php

declare(strict_types=1);

namespace Ritmo\Domain\Write\Confirm;

/**
 * Why a written date rule is not what the recalculated calendar applies.
 */
enum LivenessIssue: string
{
    /** The calendar does not apply the date rule: expired by its lead time, or out of reach. */
    case ExpiredOrOutOfReach = 'expired_or_out_of_reach';

    /** The calendar applies a floor other than the written one. */
    case FloorDiffers = 'floor_differs';
}
