<?php

declare(strict_types=1);

namespace Ritmo\Domain\Calendar\Stay;

/**
 * The rule behind a stay or arrival/departure change.
 */
enum StayReason: string
{
    /** The closed weekday's own restriction. */
    case ClosedDay = 'closed_day';

    /** A date of the closed weekday that the rule opens with its own restriction. */
    case ClosedDayOpenDate = 'closed_day_open_date';

    /** The closed day is sold out and the night before is free: departure on the closed day opens. */
    case OrphanSideClosedDaySoldOut = 'orphan_side_closed_day_sold_out';

    /** The night before is sold out and the closed day is free: arrival on the closed day opens. */
    case OrphanSidePreviousSoldOut = 'orphan_side_previous_sold_out';

    /** Today's closed day is free and the night before has passed: arrival on it opens. */
    case OrphanSidePreviousPast = 'orphan_side_previous_past';

    /** A weekday enforced open for the room: arrival and departure restrictions are cleared. */
    case OpenDay = 'open_day';

    /** A package night: stay and arrival/departure by date. */
    case Package = 'package';

    /** The minimum stay of the arrival weekday (raised to the season where it asks more). */
    case ArrivalDay = 'arrival_day';

    /** The night is free with the next one sold out: one night. */
    case OrphanNight = 'orphan_night';

    /** The free side of a closed day: one night. */
    case ClosedDayOrphan = 'closed_day_orphan';

    /** The required stay written explicitly on the date rule of the pricing system. */
    case ExplicitRule = 'explicit_rule';

    /** A holiday, closed day or package raised to its own required stay (full plan only). */
    case OwnStay = 'own_stay';
}
