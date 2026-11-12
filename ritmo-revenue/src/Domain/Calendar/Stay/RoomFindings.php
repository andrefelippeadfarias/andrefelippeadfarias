<?php

declare(strict_types=1);

namespace Ritmo\Domain\Calendar\Stay;

use DateTimeImmutable;

/**
 * Collects what the audit finds in one room while it walks the nights; frozen into a RoomAudit.
 *
 * @internal used by InventoryAudit only
 */
final class RoomFindings
{
    /** @var list<ArrivalDepartureChange> */
    public array $arrivalChanges = [];

    /** @var list<DateTimeImmutable> */
    public array $closedDayStayBelow = [];

    /** @var list<RestrictedNight> */
    public array $otherRestricted = [];

    /** @var list<StayChange> */
    public array $orphanStays = [];

    /** @var list<StayChange> */
    public array $packageStays = [];

    /** @var list<StayChange> */
    public array $arrivalStays = [];

    public function __construct(public readonly string $room)
    {
    }

    public function toAudit(): RoomAudit
    {
        return new RoomAudit($this->room, $this->arrivalChanges, $this->closedDayStayBelow, $this->otherRestricted, $this->orphanStays, $this->packageStays, $this->arrivalStays);
    }
}
