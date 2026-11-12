<?php

declare(strict_types=1);

namespace Ritmo\Domain\Calendar\Stay;

/**
 * What the pricing stay plan found on a night: an issue (out of the rule) or a reason it accepts
 * a stay below the rule.
 */
enum StayFinding: string
{
    case NoPricingData = 'no_pricing_data';
    case StayUp = 'stay_up';
    case StayDown = 'stay_down';
    case OrphanNight = 'orphan_night';
    case ExplicitStay = 'explicit_stay';
    case ClosedDayOrphanToday = 'closed_day_orphan_today';
    case OwnStayOutsideStayPlan = 'own_stay_outside_stay_plan';
    case OpenDayMasks = 'open_day_masks';
    case Held = 'held';
    case IsolatedDay = 'isolated_day';
    case OrphanAccepted = 'orphan_accepted';
    case ClosedDayOrphanAccepted = 'closed_day_orphan_accepted';

    /**
     * True for the findings that accept the night as it is.
     */
    public function isAccepted(): bool
    {
        return in_array($this, [self::Held, self::IsolatedDay, self::OrphanAccepted, self::ClosedDayOrphanAccepted], true);
    }
}
