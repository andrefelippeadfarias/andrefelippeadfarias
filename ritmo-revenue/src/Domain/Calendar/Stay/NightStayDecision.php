<?php

declare(strict_types=1);

namespace Ritmo\Domain\Calendar\Stay;

use DateTimeImmutable;
use Ritmo\Domain\Support\Days;

/**
 * The stay decision of one night of the pricing stay plan: its findings and, when the stay must
 * change, the new stay with its reason, orphan kind and proof, and the season behind it.
 *
 * @internal used by PricingStayPlan only
 */
final class NightStayDecision
{
    /** @var list<StayFinding> */
    public array $findings = [];

    private ?int $minStay = null;

    private StayReason $reason = StayReason::ArrivalDay;

    private ?OrphanKind $orphan = null;

    private ?OrphanProof $proof = null;

    private ?string $season = null;

    public function __construct(
        private readonly StayRules $rules,
        private readonly string $room,
        private readonly DateTimeImmutable $night,
        private readonly Occupancy $occupancy,
        private readonly DateTimeImmutable $today,
    ) {
    }

    /**
     * @param int      $stay        the stay in force
     * @param bool     $rulesRead   the date rules of the pricing system were read
     * @param int|null $ruleMinStay the stay set on the night's date rule
     */
    public function decide(int $stay, bool $rulesRead, ?int $ruleMinStay, bool $stayOnly): self
    {
        $free = $this->occupancy->free($this->night);
        $nextSoldOut = $this->occupancy->soldOut(Days::add($this->night, 1));
        $previousSoldOut = $this->occupancy->soldOut(Days::add($this->night, -1));
        $isolated = $stay === 1 && $free && $previousSoldOut && $nextSoldOut;
        $required = $this->rules->requiredStay($this->room, $this->night);
        $planned = $this->rules->plannedStay($this->room, $this->night, $stay, $free, $nextSoldOut);
        $closed = $this->rules->closedDay($this->room, $this->night);
        $freeSide = $free && $closed !== null && $closed->orphanSide->enabled && $this->rules->orphanAllowed($this->room, $this->night, true);
        $todaySide = $freeSide && $this->rules->closedDayWithPreviousPast($this->room, $this->night, $this->today);

        if ($this->rules->isHeld($this->room, $this->night)) {
            $this->findings[] = StayFinding::Held;
        } elseif ($planned->minStay !== null) {
            $this->arrivalRule($planned, $stay, $required, $isolated, $rulesRead, $ruleMinStay);
        } elseif ($todaySide && $stay > 1) {
            $this->planStay(StayFinding::ClosedDayOrphanToday, 1, StayReason::ClosedDayOrphan, OrphanKind::ClosedDay, $this->occupancy->proofPreviousPast($this->night));
        } elseif ($required !== null && $stay < $required) {
            $this->ownStay($stay, $required, $isolated, $freeSide && ($previousSoldOut || $todaySide), $stayOnly);
        }

        return $this;
    }

    /**
     * The item for the pricing system's date override, or null when the stay stays.
     */
    public function change(int $stayBefore, ?string $holiday, bool $keepReason): ?StayChange
    {
        if ($this->minStay === null) {
            return null;
        }

        return new StayChange(
            $this->room,
            $this->night,
            ChangeTarget::Pricing,
            $this->reason,
            minStay: $this->minStay,
            minStayBefore: $stayBefore,
            orphan: $this->orphan,
            proof: $this->proof,
            holiday: $holiday,
            season: $this->season,
            keepReason: $keepReason,
        );
    }

    public function isOrphan(): bool
    {
        return $this->orphan !== null;
    }

    private function arrivalRule(PlannedStay $planned, int $stay, ?int $required, bool $isolated, bool $rulesRead, ?int $ruleMinStay): void
    {
        $target = (int) $planned->minStay;
        if ($target > $stay && $isolated) {
            $this->findings[] = StayFinding::IsolatedDay;
        } elseif ($target > $stay) {
            $this->planStay(StayFinding::StayUp, $target, StayReason::ArrivalDay);
        } elseif ($target < $stay && $planned->orphan) {
            $this->planStay(StayFinding::OrphanNight, $target, StayReason::OrphanNight, OrphanKind::NextNight, $this->occupancy->proof($this->night, OrphanProof::NEXT));
        } elseif ($target < $stay) {
            $this->planStay(StayFinding::StayDown, $target, StayReason::ArrivalDay);
        } elseif ($planned->orphan && $required !== null && $stay < $required) {
            $this->findings[] = StayFinding::OrphanAccepted;
        } elseif ($rulesRead && !$planned->orphan && $required !== null && $required >= 2 && ($ruleMinStay === null || $ruleMinStay < $required)) {
            $this->planStay(StayFinding::ExplicitStay, max($required, $stay), StayReason::ExplicitRule);
        }
        if ($this->minStay !== null) {
            $season = $this->rules->seasonOutsideHolidays($this->night);
            if ($season !== null && $season->minStay > ($this->rules->arrivalWeekdayStay($this->room, $this->night) ?? 1) && $this->minStay === $season->minStay) {
                $this->season = $season->name;
            }
        }
    }

    /**
     * A closed day, holiday or package below its own stay.
     */
    private function ownStay(int $stay, int $required, bool $isolated, bool $closedDayOrphan, bool $stayOnly): void
    {
        if ($isolated) {
            $this->findings[] = StayFinding::IsolatedDay;
        } elseif ($stay === 1 && $closedDayOrphan) {
            $this->findings[] = StayFinding::ClosedDayOrphanAccepted;
        } elseif ($stayOnly) {
            $this->findings[] = StayFinding::OwnStayOutsideStayPlan;
        } else {
            $this->planStay(StayFinding::StayUp, $required, StayReason::OwnStay);
        }
    }

    private function planStay(StayFinding $finding, int $minStay, StayReason $reason, ?OrphanKind $orphan = null, ?OrphanProof $proof = null): void
    {
        $this->findings[] = $finding;
        $this->minStay = $minStay;
        $this->reason = $reason;
        $this->orphan = $orphan;
        $this->proof = $proof;
    }
}
