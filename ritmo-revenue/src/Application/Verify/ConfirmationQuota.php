<?php

declare(strict_types=1);

namespace Ritmo\Application\Verify;

use DateTimeImmutable;

/**
 * Whether the recalculation quota leaves a slot to confirm a room now (the write path's quota,
 * cota_recalculo.situacao). It never records an attempt: the recalculation itself is a write
 * step, recorded by the write path before the call.
 */
interface ConfirmationQuota
{
    public function allows(string $roomCode, DateTimeImmutable $now): bool;
}
