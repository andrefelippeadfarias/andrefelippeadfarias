<?php

declare(strict_types=1);

namespace Ritmo\Domain\Write\Confirm;

/**
 * The "live" level of a written night: what the recalculated calendar applies.
 */
enum LiveStatus: string
{
    /** In force, and the price obeys it (or the night is sold out: only the rule is judged). */
    case Live = 'live';

    /** The calendar does not apply the date rule (expired or out of reach): delete and write it again as a group. */
    case Inactive = 'inactive';

    /** The calendar applies another floor. */
    case FloorDiffers = 'floor_differs';

    /** The price is under the written floor beyond the slack. */
    case BelowFloor = 'below_floor';

    /** The price is over the written ceiling beyond the slack. */
    case AboveCeiling = 'above_ceiling';

    /** The minimum stay differs on a night that is not isolated. */
    case StayDiffers = 'stay_differs';

    /** A deleted date rule is still applied. */
    case DeletionStillLive = 'deletion_still_live';

    /** The recalculated calendar has no row for the night. */
    case NoData = 'no_data';

    public function isConfirmed(): bool
    {
        return $this === self::Live;
    }
}
