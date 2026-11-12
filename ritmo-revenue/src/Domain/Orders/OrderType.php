<?php

declare(strict_types=1);

namespace Ritmo\Domain\Orders;

/**
 * What an owner order does to the nights it covers.
 *
 * Reference: ordens.py (tipo).
 */
enum OrderType: string
{
    /** The owner fixed the price of the night: the engine never touches it. */
    case FixedPrice = 'fixed_price';

    /** A floor set by the owner replaces the standard floors; the engine keeps pricing the night. */
    case Floor = 'floor';

    /** A target market percentile (and a floor) for weak nights; the engine keeps pricing the night. */
    case Target = 'target';

    /** A standing rule applied by the engine; it authorises what it releases, where it is in force. */
    case Rule = 'rule';

    /** A change the owner made by hand in the pricing system: the night is frozen for a few days. */
    case Manual = 'manual';

    /** A trigger to check later; it authorises nothing by itself. */
    case FollowUp = 'follow_up';

    /** A stay or arrival restriction written to the PMS. */
    case Restriction = 'restriction';
}
