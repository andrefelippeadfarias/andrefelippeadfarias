<?php

declare(strict_types=1);

namespace Ritmo\Domain\Model;

/**
 * Status of a booking, in the plugin's own vocabulary (each PMS connector maps its statuses).
 */
enum BookingStatus: string
{
    case Confirmed = 'confirmed';

    /** Received and holding inventory, not yet acknowledged in the PMS. */
    case NewBooking = 'new';

    /** A booking request that does not hold inventory yet. */
    case Request = 'request';

    case Cancelled = 'cancelled';

    /** A block placed in the PMS without a sale. */
    case Blocked = 'blocked';

    case Inquiry = 'inquiry';

    /**
     * True when the booking takes units out of the inventory as a sale.
     */
    public function occupies(): bool
    {
        return $this === self::Confirmed || $this === self::NewBooking;
    }
}
