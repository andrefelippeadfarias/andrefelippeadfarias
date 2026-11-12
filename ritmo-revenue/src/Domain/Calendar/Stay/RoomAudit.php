<?php

declare(strict_types=1);

namespace Ritmo\Domain\Calendar\Stay;

use DateTimeImmutable;

/**
 * What the audit of one room's PMS inventory found.
 *
 * Changes for the PMS: arrival/departure restrictions (closed days, their open dates and orphan
 * side, enforced open days, packages), package stays and arrival-rule stays. orphanStays holds the
 * one-night stays of a closed day's orphan side: for the PMS when today's closed day follows a
 * past night (with its proof), otherwise for the pricing system. closedDayStayBelow and
 * otherRestricted are only reported.
 */
final class RoomAudit
{
    /**
     * @param list<ArrivalDepartureChange> $arrivalChanges
     * @param list<DateTimeImmutable>      $closedDayStayBelow
     * @param list<RestrictedNight>        $otherRestricted
     * @param list<StayChange>             $orphanStays
     * @param list<StayChange>             $packageStays
     * @param list<StayChange>             $arrivalStays
     */
    public function __construct(
        public readonly string $roomCode,
        public readonly array $arrivalChanges,
        public readonly array $closedDayStayBelow,
        public readonly array $otherRestricted,
        public readonly array $orphanStays,
        public readonly array $packageStays,
        public readonly array $arrivalStays,
    ) {
    }

    /**
     * The orphan stays that go to the PMS (today's closed day after a past night).
     *
     * @return list<StayChange>
     */
    public function pmsOrphanStays(): array
    {
        return array_values(array_filter($this->orphanStays, static fn (StayChange $c): bool => $c->target === ChangeTarget::Pms));
    }
}
