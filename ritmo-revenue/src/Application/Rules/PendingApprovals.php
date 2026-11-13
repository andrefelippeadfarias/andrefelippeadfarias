<?php

declare(strict_types=1);

namespace Ritmo\Application\Rules;

/**
 * The plans waiting for approval of each property, as far as saving rules needs them: how many
 * nights wait, and ending them all (VERIFY.md 6: an approval given before a rules change never
 * writes after it).
 */
interface PendingApprovals
{
    /**
     * The nights waiting for approval in the property's open requests.
     */
    public function waitingNights(string $propertyId): int;

    /**
     * Ends every open request of the property for the reason; returns how many ended.
     */
    public function expireAll(string $propertyId, string $reason): int;
}
