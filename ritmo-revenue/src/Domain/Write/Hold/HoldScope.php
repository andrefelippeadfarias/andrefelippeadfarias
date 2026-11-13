<?php

declare(strict_types=1);

namespace Ritmo\Domain\Write\Hold;

/**
 * Where a cancellation-wave hold stops price cuts.
 */
enum HoldScope: string
{
    /** Every night of the property (a hidden or compromised listing sells nothing at a lower price). */
    case Property = 'property';

    /** Only the nights of the cancelled bookings. */
    case CancelledNights = 'cancelled_nights';
}
