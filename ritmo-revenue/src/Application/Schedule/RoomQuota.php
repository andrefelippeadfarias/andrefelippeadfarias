<?php

declare(strict_types=1);

namespace Ritmo\Application\Schedule;

/**
 * Whether the confirmation recalculation of a room still fits the recalculation quota (the write
 * path's quota, spec 03). Asking records nothing.
 */
interface RoomQuota
{
    public function permits(string $roomCode): bool;
}
