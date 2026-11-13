<?php

declare(strict_types=1);

namespace Ritmo\Domain\Stay\Write;

use Ritmo\Domain\Calendar\Stay\OrphanKind;
use Ritmo\Domain\Calendar\Stay\PricingStayEntry;
use Ritmo\Domain\Calendar\Stay\StayReason;
use Ritmo\Domain\Plan\OrphanClaim;
use Ritmo\Domain\Plan\OrphanClaimKind;
use Ritmo\Domain\Plan\PlanItem;

/**
 * Turns a night of the pricing stay plan (M2) into a plan item: the minimum stay and/or the
 * arrival and departure masks, the orphan claim with its proof, whether the date rule keeps its
 * reason, and the reason code STAY_<reason>.
 */
final class StayItemFactory
{
    /** Reason of a pricing item created to pair a PMS change (spec 06, 3.4 step 4). */
    public const PAIR_REASON = 'STAY_PMS_PAIR';

    private function __construct()
    {
    }

    /**
     * Null for an entry without an item (findings only).
     */
    public static function toPlanItem(PricingStayEntry $entry): ?PlanItem
    {
        if (!$entry->hasItem()) {
            return null;
        }
        $stay = $entry->stay;
        $masks = $entry->masks;
        $reason = ($stay ?? $masks)->reason;

        return new PlanItem(
            $entry->roomCode,
            $entry->night,
            minStay: $stay?->minStay,
            reasonCode: self::code($reason),
            reasonParams: array_filter(['holiday' => $entry->holiday, 'stay_before' => $entry->stayBefore, 'season' => $stay?->season], static fn ($v): bool => $v !== null),
            checkInMask: $masks?->checkinMask,
            checkOutMask: $masks?->checkoutMask,
            orphan: $stay?->orphan === null ? null : new OrphanClaim($stay->orphan === OrphanKind::NextNight ? OrphanClaimKind::NextNight : OrphanClaimKind::ClosedDayArrival, $stay->proof),
            keepReason: ($stay ?? $masks)->keepReason,
            readsOccupancy: $stay?->orphan !== null,
        );
    }

    /**
     * The pricing item that carries a PMS change's pushed value (same value, same proof).
     *
     * @param array{0: string, 1: string}|null $masks check-in and check-out masks for a restriction
     */
    public static function pairOf(PmsChangeEntry $change, ?array $masks): ?PlanItem
    {
        $minStay = $change->minStay();
        if ($minStay === null && $masks === null) {
            return null;
        }
        $orphan = null;
        if ($change->proof !== null && $minStay === 1) {
            $orphan = new OrphanClaim($change->proof->neighbour === 'next' ? OrphanClaimKind::NextNight : OrphanClaimKind::ClosedDayArrival, $change->proof);
        }

        return new PlanItem(
            $change->roomCode,
            $change->night,
            minStay: $minStay,
            reasonCode: self::PAIR_REASON,
            reasonParams: ['pms_reason' => $change->reason],
            checkInMask: $masks[0] ?? null,
            checkOutMask: $masks[1] ?? null,
            orphan: $orphan,
            keepReason: true,
            readsOccupancy: $orphan !== null,
        );
    }

    public static function code(StayReason $reason): string
    {
        return 'STAY_' . strtoupper($reason->value);
    }
}
