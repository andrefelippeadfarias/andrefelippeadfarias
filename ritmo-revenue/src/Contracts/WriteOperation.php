<?php

declare(strict_types=1);

namespace Ritmo\Contracts;

/**
 * The kinds of request that change an external system. Nothing else is ever written: never a
 * price to the PMS, never a booking.
 */
enum WriteOperation: string
{
    /** Date rules of a listing of the pricing system (floor, ceiling, stay, arrival/departure days). */
    case OverridesWrite = 'overrides_write';

    /** Deletion of every field of the date rules of a listing on some nights. */
    case OverridesDelete = 'overrides_delete';

    /** A recalculation of a listing by the pricing system (a metered call). */
    case Recalculation = 'recalculation';

    /** Minimum, base or maximum of a listing: only with a human approval naming the change. */
    case ListingUpdate = 'listing_update';

    /** Availability, stay, arrival/departure restriction and channel limits of the PMS calendar. */
    case PmsCalendar = 'pms_calendar';

    public function isPms(): bool
    {
        return $this === self::PmsCalendar;
    }
}
