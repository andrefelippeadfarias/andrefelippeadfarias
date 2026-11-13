<?php

declare(strict_types=1);

namespace Ritmo\Domain\Calendar\Stay;

use DateTimeImmutable;
use Ritmo\Domain\Model\InventoryNight;
use Ritmo\Domain\Model\Restriction;
use Ritmo\Domain\Support\Days;

/**
 * Compares the PMS inventory (units for sale, minimum and maximum stay, arrival/departure
 * restriction) with the stay rules and lists, per room, the changes the PMS needs. Prices and
 * availability are never changed: a blackout night keeps its blackout.
 *
 * Per night, highest rule first: a package night takes its stay and restriction (and nothing
 * else); a closed day takes its restriction, its open date's or its orphan side's, and its own
 * stay is reported when below; an enforced open day loses its arrival/departure restriction; any
 * other restriction is reported; then the arrival rule plans the stay (an isolated free night
 * between two sold-out nights keeps a one-night stay).
 *
 * Reference: calendario_12m.classificar_beds24.
 */
final class InventoryAudit
{
    private const ARRIVAL_RESTRICTIONS = [Restriction::NoCheckin, Restriction::NoCheckout, Restriction::NoCheckinCheckout];

    public function __construct(
        private readonly StayRules $rules,
        private readonly StaySyncCapability $capability,
    ) {
    }

    /**
     * @param array<string, array<string, InventoryNight>> $inventory room code => 'YYYY-MM-DD' => night
     * @param DateTimeImmutable|null                       $readAt    instant the inventory was read
     *
     * @return list<RoomAudit> sorted by room code
     */
    public function audit(array $inventory, DateTimeImmutable $from, DateTimeImmutable $to, DateTimeImmutable $today, ?DateTimeImmutable $readAt): array
    {
        ksort($inventory, SORT_STRING);
        $out = [];
        foreach ($inventory as $room => $nights) {
            $out[] = $this->auditRoom((string) $room, $nights, $from, $to, $today, $readAt);
        }

        return $out;
    }

    /**
     * @param array<string, InventoryNight> $nights
     */
    private function auditRoom(string $room, array $nights, DateTimeImmutable $from, DateTimeImmutable $to, DateTimeImmutable $today, ?DateTimeImmutable $readAt): RoomAudit
    {
        ksort($nights, SORT_STRING);
        $found = new RoomFindings($room);
        $occupancy = Occupancy::fromInventory($nights, $this->rules->room($room)->units, $readAt);
        $first = Days::key($from);
        $last = Days::key($to);
        foreach ($nights as $key => $night) {
            if ($key >= $first && $key <= $last) {
                $this->night($found, $nights, $night, $occupancy, $today);
            }
        }

        return $found->toAudit();
    }

    /**
     * @param array<string, InventoryNight> $nights
     */
    private function night(RoomFindings $found, array $nights, InventoryNight $night, Occupancy $occupancy, DateTimeImmutable $today): void
    {
        $package = $this->rules->packageNight($found->room, $night->date);
        if ($package !== null) {
            $this->packageStay($found, $night, $package);
            if ($package->restriction !== null) {
                $this->packageRestriction($found, $night, $package);

                return;
            }
        }
        $closed = $this->rules->closedDay($found->room, $night->date);
        if ($closed !== null) {
            $this->closedDay($found, $closed, $night, $nights[Days::key(Days::add($night->date, -1))] ?? null, $occupancy, $today);
            if ($closed->minStay !== null) {
                return;
            }
        } else {
            $this->openDayOrReport($found, $night);
        }
        $this->arrivalStay($found, $night, $occupancy);
    }

    private function packageStay(RoomFindings $found, InventoryNight $night, PackageNight $package): void
    {
        $min = $package->minStay !== null && ($night->minStay ?? 1) !== $package->minStay;
        $max = $package->maxStay !== null && $night->maxStay !== $package->maxStay;
        if (!$min && !$max) {
            return;
        }
        $found->packageStays[] = new StayChange(
            $found->room,
            $night->date,
            ChangeTarget::Pms,
            StayReason::Package,
            minStay: $min ? $package->minStay : null,
            minStayBefore: $min ? ($night->minStay ?? 1) : null,
            maxStay: $max ? $package->maxStay : null,
            maxStayBefore: $max ? $night->maxStay : null,
            alsoPricing: ($min && $this->pushes(StaySyncField::MinStay, $found->room)) || ($max && $this->pushes(StaySyncField::MaxStay, $found->room)),
        );
    }

    private function packageRestriction(RoomFindings $found, InventoryNight $night, PackageNight $package): void
    {
        $this->restriction($found, $night, $package->restriction ?? Restriction::None, StayReason::Package);
        $closed = $this->rules->closedDay($found->room, $night->date);
        if ($closed?->minStay !== null && ($night->minStay ?? 1) < $closed->minStay && $package->minStay === null) {
            $found->closedDayStayBelow[] = $night->date;
        }
    }

    private function closedDay(RoomFindings $found, ClosedDayRule $closed, InventoryNight $night, ?InventoryNight $previous, Occupancy $occupancy, DateTimeImmutable $today): void
    {
        $side = ClosedDaySide::of($this->rules, $found->room, $closed, $night, $previous, $occupancy, $today);
        $this->restriction($found, $night, $side->restriction, $side->reason, $side->proof);
        $stay = $night->minStay ?? 1;
        $freeSideAllowed = $side->freeClosedDay && $this->rules->orphanAllowed($found->room, $night->date, true);
        // the stay of a free closed day goes to the PMS only once the night before has passed (the
        // reference's batch); a future one goes to the pricing system, whose push carries it
        $previousPast = $side->reason === StayReason::OrphanSidePreviousPast;
        if ($side->soldOutClosedDay && $previous !== null) {
            $this->previousNightOrphan($found, $previous);
        } elseif ($freeSideAllowed && $stay > 1) {
            $found->orphanStays[] = new StayChange(
                $found->room,
                $night->date,
                $previousPast ? ChangeTarget::Pms : ChangeTarget::Pricing,
                StayReason::ClosedDayOrphan,
                minStay: 1,
                minStayBefore: $stay,
                alsoPricing: $previousPast && $this->pushes(StaySyncField::MinStay, $found->room),
                orphan: OrphanKind::ClosedDay,
                proof: $previousPast ? $side->proof : null,
                holiday: $this->rules->holiday($night->date)?->name,
            );
        }
        if ($closed->minStay !== null && $stay < $closed->minStay && !$freeSideAllowed) {
            $found->closedDayStayBelow[] = $night->date;
        }
    }

    /**
     * The free night before a sold-out closed day sells alone with one night, where the arrival
     * rule (which has its own orphan night) is not in force and outside holidays.
     */
    private function previousNightOrphan(RoomFindings $found, InventoryNight $previous): void
    {
        $stay = $previous->minStay ?? 1;
        if ($this->rules->config()->arrival->inForce($previous->date) || $stay <= 1 || $this->rules->holiday($previous->date) !== null) {
            return;
        }
        $found->orphanStays[] = new StayChange($found->room, $previous->date, ChangeTarget::Pricing, StayReason::OrphanNight, minStay: 1, minStayBefore: $stay, orphan: OrphanKind::NextNight);
    }

    private function openDayOrReport(RoomFindings $found, InventoryNight $night): void
    {
        if ($this->rules->openDayApplies($found->room, $night->date) && in_array($night->restriction, self::ARRIVAL_RESTRICTIONS, true)) {
            $this->restriction($found, $night, Restriction::None, StayReason::OpenDay);
        } elseif ($night->restriction !== Restriction::None) {
            $found->otherRestricted[] = new RestrictedNight($night->date, $night->restriction);
        }
    }

    private function arrivalStay(RoomFindings $found, InventoryNight $night, Occupancy $occupancy): void
    {
        $date = $night->date;
        if (!$this->rules->config()->arrival->inForce($date)) {
            return;
        }
        $stay = $night->minStay ?? 1;
        $free = $occupancy->free($date);
        $nextSoldOut = $occupancy->soldOut(Days::add($date, 1));
        $planned = $this->rules->plannedStay($found->room, $date, $stay, $free, $nextSoldOut);
        $isolated = $stay === 1 && $free && $nextSoldOut && $occupancy->soldOut(Days::add($date, -1));
        if ($planned->minStay === null || $planned->minStay === $stay || ($planned->minStay > $stay && $isolated)) {
            return;
        }
        $orphan = $planned->orphan && $planned->minStay === 1;
        $found->arrivalStays[] = new StayChange(
            $found->room,
            $date,
            ChangeTarget::Pms,
            $orphan ? StayReason::OrphanNight : StayReason::ArrivalDay,
            minStay: $planned->minStay,
            minStayBefore: $stay,
            alsoPricing: $this->pushes(StaySyncField::MinStay, $found->room),
            orphan: $orphan ? OrphanKind::NextNight : null,
            proof: $orphan ? $occupancy->proof($date, OrphanProof::NEXT) : null,
            holiday: $this->rules->holiday($date)?->name,
        );
    }

    private function restriction(RoomFindings $found, InventoryNight $night, Restriction $target, StayReason $reason, ?OrphanProof $proof = null): void
    {
        if ($night->restriction === $target || $night->restriction === Restriction::Blackout) {
            return;
        }
        $found->arrivalChanges[] = ArrivalDepartureChange::forPms(
            $found->room,
            $night->date,
            $reason,
            $night->restriction,
            $target,
            $this->pushes(StaySyncField::ArrivalDeparture, $found->room),
            $proof,
        );
    }

    private function pushes(StaySyncField $field, string $room): bool
    {
        return $this->rules->config()->pricingSync->pushes($field, $room, $this->capability);
    }
}
