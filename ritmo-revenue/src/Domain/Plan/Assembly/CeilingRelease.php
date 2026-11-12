<?php

declare(strict_types=1);

namespace Ritmo\Domain\Plan\Assembly;

use Ritmo\Domain\Calendar\Stay\Occupancy;
use Ritmo\Domain\Calendar\Stay\OrphanKind;
use Ritmo\Domain\Calendar\Stay\StayHook;
use Ritmo\Domain\Model\DateOverride;
use Ritmo\Domain\Model\RoomType;
use Ritmo\Domain\Plan\OrphanClaim;
use Ritmo\Domain\Plan\OrphanClaimKind;
use Ritmo\Domain\Plan\PlanDeletion;
use Ritmo\Domain\Pricing\RuleInForce;

/**
 * Frees the ceiling of the items that should have none. The pricing system merges the fields of a
 * date rule, so an item without a ceiling leaves an old ceiling in place: where the rule in force
 * has one, the night goes to the delete-first plan and the item rewrites what the deleted rule
 * held (stay and masks), corrected by the stay rules.
 *
 * Reference: pace.ajustar_tetos.
 */
final class CeilingRelease
{
    public const DELETE_REASON = 'CEILING_RELEASE_DELETE';

    public function __construct(
        private readonly StayHook $stay,
        private readonly float $moneySlack = 1.0,
    ) {
    }

    /**
     * Changes the items in place and returns the nights whose rule is deleted first.
     *
     * @param list<WorkItem>           $items
     * @param array<string, Occupancy> $occupancy room code => what the run's calendar says of its nights
     *
     * @return list<PlanDeletion>
     */
    public function apply(array $items, RunInputs $inputs, array $occupancy): array
    {
        $deletions = [];
        foreach ($items as $item) {
            $room = $inputs->rooms[$item->room];
            $rule = $inputs->override($item->room, $item->night);
            if (!$this->releases($item, $room, $rule)) {
                continue;
            }
            $item->maxPrice = null;
            if ($rule === null || ($rule->maxPercent === null && $rule->maxFixed === null)) {
                continue;
            }
            $deletions[] = new PlanDeletion($item->room, $item->night, self::DELETE_REASON, sprintf('Frees the ceiling (%.0f) before the floor is written again', RuleInForce::ceiling($rule, $room->base) ?? 0.0));
            $this->rewrite($item, $rule, $occupancy[$item->room] ?? null, $inputs);
        }

        return $deletions;
    }

    /**
     * A ceiling at the listing maximum means "no ceiling"; a new floor above the ceiling in force
     * needs that ceiling gone.
     */
    private function releases(WorkItem $item, RoomType $room, ?DateOverride $rule): bool
    {
        if ($item->maxPrice !== null) {
            return $item->maxPrice >= $room->max - $this->moneySlack;
        }
        $ceiling = RuleInForce::ceiling($rule, $room->base);

        return $item->minPrice !== null && $ceiling !== null && $item->minPrice > $ceiling + $this->moneySlack;
    }

    private function rewrite(WorkItem $item, DateOverride $rule, ?Occupancy $occupancy, RunInputs $inputs): void
    {
        $item->minStay ??= $rule->minStay;
        $item->checkInMask ??= $rule->checkinMask;
        $item->checkOutMask ??= $rule->checkoutMask;
        if ($occupancy === null) {
            return;
        }
        $stay = $this->stay->rewrite($item->room, $item->night, $item->minStay, $item->checkOutMask, $occupancy, $inputs->today);
        $item->minStay = $stay->minStay;
        $item->checkOutMask = $stay->checkoutMask;
        if ($stay->orphan !== null) {
            $kind = $stay->orphan === OrphanKind::NextNight ? OrphanClaimKind::NextNight : OrphanClaimKind::ClosedDayArrival;
            $item->orphan = new OrphanClaim($kind, $stay->proof);
        }
    }
}
