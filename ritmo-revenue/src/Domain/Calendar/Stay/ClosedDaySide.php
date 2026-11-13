<?php

declare(strict_types=1);

namespace Ritmo\Domain\Calendar\Stay;

use DateTimeImmutable;
use Ritmo\Domain\Model\InventoryNight;
use Ritmo\Domain\Model\Restriction;

/**
 * The restriction a closed day must have in the PMS and which side of it, if any, is an orphan.
 *
 * An open date of the rule wins. Otherwise, with the orphan side on and both nights read: the
 * closed day sold out with the night before free opens its departure (soldOutClosedDay); the
 * night before sold out (or past, on today's closed day) with the closed day free opens its
 * arrival (freeClosedDay, with a proof when the night before is past).
 *
 * Reference: calendario_12m.classificar_beds24 (the Saturday branch).
 */
final class ClosedDaySide
{
    private function __construct(
        public readonly Restriction $restriction,
        public readonly StayReason $reason,
        public readonly bool $soldOutClosedDay = false,
        public readonly bool $freeClosedDay = false,
        public readonly ?OrphanProof $proof = null,
    ) {
    }

    public static function of(StayRules $rules, string $room, ClosedDayRule $closed, InventoryNight $night, ?InventoryNight $previous, Occupancy $occupancy, DateTimeImmutable $today): self
    {
        $open = $closed->openDate($night->date);
        if ($open !== null) {
            return new self($open, StayReason::ClosedDayOpenDate);
        }
        $side = $closed->orphanSide;
        if (!$side->enabled) {
            return new self($closed->restriction, StayReason::ClosedDay);
        }
        $past = $rules->closedDayWithPreviousPast($room, $night->date, $today) && $rules->orphanAllowed($room, $night->date, true);
        $previousAvailable = $past ? 0 : $previous?->available;
        if ($previousAvailable === null) {
            return new self($closed->restriction, StayReason::ClosedDay);
        }
        if ($night->available <= 0 && $previousAvailable > 0) {
            return new self($side->whenClosedDaySoldOut, StayReason::OrphanSideClosedDaySoldOut, soldOutClosedDay: true, proof: $occupancy->proof($night->date, OrphanProof::PREVIOUS));
        }
        if ($previousAvailable <= 0 && $night->available > 0) {
            return $past
                ? new self($side->whenPreviousSoldOut, StayReason::OrphanSidePreviousPast, freeClosedDay: true, proof: $occupancy->proofPreviousPast($night->date))
                : new self($side->whenPreviousSoldOut, StayReason::OrphanSidePreviousSoldOut, freeClosedDay: true, proof: $occupancy->proof($night->date, OrphanProof::PREVIOUS));
        }

        return new self($closed->restriction, StayReason::ClosedDay);
    }
}
