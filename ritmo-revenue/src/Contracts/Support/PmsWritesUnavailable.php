<?php

declare(strict_types=1);

namespace Ritmo\Contracts\Support;

use Ritmo\Contracts\Exception\NotAvailableInThisMilestone;
use Ritmo\Contracts\WriteToken;

/**
 * The write half of PmsConnector for a read-only plugin: every method throws.
 *
 * Every PmsConnector implementation uses this trait until the milestone that enables writing,
 * so "no code path writes to the PMS" is true by construction and checked in one place.
 */
trait PmsWritesUnavailable
{
    /**
     * @param list<array<string, mixed>> $changes
     */
    public function writeCalendar(WriteToken $token, array $changes): void
    {
        throw NotAvailableInThisMilestone::forOperation('PmsConnector::writeCalendar');
    }
}
