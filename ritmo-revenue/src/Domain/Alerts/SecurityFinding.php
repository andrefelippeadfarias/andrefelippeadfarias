<?php

declare(strict_types=1);

namespace Ritmo\Domain\Alerts;

use Ritmo\Domain\Model\BookingRecord;
use Ritmo\Domain\Write\Hold\Wave;

/**
 * What the security watch found: a booking (unknown origin, no value) or a burst of cancellations.
 */
final class SecurityFinding
{
    private function __construct(
        public readonly SecurityFindingKind $kind,
        public readonly ?BookingRecord $booking,
        public readonly ?Wave $wave,
    ) {
    }

    public static function booking(SecurityFindingKind $kind, BookingRecord $booking): self
    {
        return new self($kind, $booking, null);
    }

    public static function burst(Wave $wave): self
    {
        return new self(SecurityFindingKind::CancellationBurst, null, $wave);
    }
}
