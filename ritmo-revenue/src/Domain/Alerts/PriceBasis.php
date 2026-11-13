<?php

declare(strict_types=1);

namespace Ritmo\Domain\Alerts;

/**
 * Which PMS price a booking is compared with. "at_booking" takes the price of the latest
 * inventory read at or before the booking was made (current price when none is older), so a
 * price raised after the sale does not make it look low; "current" is the reference's way.
 */
enum PriceBasis: string
{
    case AtBooking = 'at_booking';
    case Current = 'current';
}
