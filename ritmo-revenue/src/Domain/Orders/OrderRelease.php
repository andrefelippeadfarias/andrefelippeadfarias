<?php

declare(strict_types=1);

namespace Ritmo\Domain\Orders;

/**
 * What an owner order allows that the safety rules would otherwise refuse. An item that needs one
 * of these must cite an order in force that releases it.
 *
 * Reference: ordens.py and validar_plano.py (libera).
 */
enum OrderRelease: string
{
    /** A fixed price (floor equal to ceiling, or a price override). */
    case FixedPrice = 'fixed_price';

    /** A floor below the standard owner floors (never below the safety limit). */
    case BelowStandardFloor = 'below_standard_floor';

    /** A rise beyond the weekly change limit of the listing. */
    case AboveWeeklyRiseLimit = 'above_weekly_rise_limit';

    /** A minimum stay other than the arrival-day rule. */
    case Stay = 'stay';

    /** A one-night stay on an orphan night. */
    case OrphanStay = 'orphan_stay';

    /** Arrival or departure days other than the configured ones. */
    case ArrivalDeparture = 'arrival_departure';

    /** A price under the lower edge of a discount band whose target is the room. */
    case DiscountBand = 'discount_band';

    /** A change to the listing itself (base, minimum, maximum). */
    case Listing = 'listing';

    /** A change to the PMS inventory. */
    case Inventory = 'inventory';

    /** A change to a customisation of the pricing system. */
    case Customization = 'customization';
}
