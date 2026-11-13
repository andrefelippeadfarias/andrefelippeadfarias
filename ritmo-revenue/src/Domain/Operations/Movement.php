<?php

declare(strict_types=1);

namespace Ritmo\Domain\Operations;

use Ritmo\Domain\Model\BookingRecord;
use Ritmo\Domain\Model\BookingStatus;

/**
 * A booking that changed since the previous run, with the version processed before it (when the
 * property has one), so a change of dates or room frees the old nights.
 */
final class Movement
{
    public function __construct(
        public readonly MovementKind $kind,
        public readonly BookingRecord $booking,
        public readonly ?BookingVersion $previous = null,
    ) {
    }

    /**
     * True when the booking takes or took units as a sale: occupying now or before the change, or a
     * cancellation (it occupied until it was cancelled). A blocked period is not a sale.
     */
    public function touchesSales(): bool
    {
        if ($this->booking->status === BookingStatus::Blocked && ($this->previous === null || $this->previous->status === BookingStatus::Blocked)) {
            return false;
        }

        return $this->booking->status->occupies()
            || ($this->previous !== null && $this->previous->status->occupies())
            || ($this->kind === MovementKind::Cancelled && $this->previous === null);
    }
}
