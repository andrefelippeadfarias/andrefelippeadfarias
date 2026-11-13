<?php

declare(strict_types=1);

namespace Ritmo\Domain\Write\State;

/**
 * The external system a written or observed value lives in.
 */
enum Provider: string
{
    /** Date rules of the pricing system (floor, ceiling, stay, arrival and departure days). */
    case Pricing = 'pricing';

    /** The PMS calendar (stay, restriction, availability, channel limits). Never a price. */
    case Pms = 'pms';

    /** Listing settings of the pricing system (minimum, base, maximum). */
    case Listing = 'listing';
}
