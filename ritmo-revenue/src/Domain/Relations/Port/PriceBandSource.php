<?php

declare(strict_types=1);

namespace Ritmo\Domain\Relations\Port;

use DateTimeImmutable;

/**
 * The market band of a room, in the sent plan.
 */
interface PriceBandSource
{
    /** The band of the room tonight, or null (no band, wrong night type, ladder night). */
    public function sentBand(string $room, DateTimeImmutable $night): ?SentBand;

    /** The last night that may receive the band measured today, or null when the room has no band. */
    public function lastNight(string $room, DateTimeImmutable $today): ?DateTimeImmutable;
}
