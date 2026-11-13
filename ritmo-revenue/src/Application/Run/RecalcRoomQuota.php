<?php

declare(strict_types=1);

namespace Ritmo\Application\Run;

use DateTimeImmutable;
use Ritmo\Application\Schedule\RoomQuota;
use Ritmo\Application\Verify\ConfirmationQuota;
use Ritmo\Application\Write\Quota\RecalcGuard;

/**
 * Whether a room's listing may still be recalculated, by the write path's recalculation quota
 * (three per listing in 24 hours, ten per account a minute, the wait after a rate limit). Asking
 * records nothing. Rooms without a listing are never allowed.
 */
final class RecalcRoomQuota implements RoomQuota, ConfirmationQuota
{
    /**
     * @param array<string, string> $listings room code => listing id of the pricing system
     */
    public function __construct(
        private readonly RecalcGuard $guard,
        private readonly array $listings,
        private readonly DateTimeImmutable $runStart,
    ) {
    }

    public function permits(string $roomCode): bool
    {
        $listing = $this->listings[$roomCode] ?? '';

        return $listing !== '' && $this->guard->verdict($listing, $this->runStart)->allowed;
    }

    public function allows(string $roomCode, DateTimeImmutable $now): bool
    {
        return $this->permits($roomCode);
    }
}
