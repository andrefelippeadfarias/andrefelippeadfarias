<?php

declare(strict_types=1);

namespace Ritmo\Domain\Alerts;

use Ritmo\Domain\Model\BookingRecord;

/**
 * A booking worth less than expected. Amounts in the property currency, rounded for display as
 * the reference does (total and expected to cents, ratio to three decimals); the alert decision
 * uses the ratio before rounding.
 */
final class BelowExpectedFinding
{
    public function __construct(
        public readonly BookingRecord $booking,
        public readonly int $nights,
        public readonly float $total,
        public readonly float $expected,
        public readonly float $ratio,
        public readonly AlertSeverity $severity,
    ) {
    }
}
