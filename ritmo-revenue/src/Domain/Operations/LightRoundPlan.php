<?php

declare(strict_types=1);

namespace Ritmo\Domain\Operations;

use Ritmo\Domain\Support\DateRange;

/**
 * The scope of one light run.
 *
 * - filter: the room nights the plan assembly works on (RunInputs::$lightFilter).
 * - paceRooms take pace and ladder steps on their filter nights; safetyOnlyRooms (no quota for the
 *   confirmation recalculation) keep only the protection of their sold-out nights, and the report
 *   says "not confirmed (recalculation quota)".
 * - protectNow: the flips near today the run protects or prices at once.
 * - orphanRooms re-plan their orphan-night stays over orphanWindow in both systems, from the
 *   inventory read now.
 * - deferredKeys: nights whose work waits for the next full run.
 */
final class LightRoundPlan
{
    /**
     * @param list<LightTrigger>     $triggers       why the run goes on; empty: it ends with the three-line report
     * @param array<string, true>    $filter         PlanItem keys
     * @param list<string>           $affectedRooms
     * @param list<string>           $paceRooms
     * @param list<string>           $safetyOnlyRooms
     * @param list<SoldOutFlip>      $protectNow
     * @param list<string>           $orphanRooms
     * @param array<string, DeferredWork> $deferredKeys PlanItem key => what waits for the full run
     */
    public function __construct(
        public readonly array $triggers,
        public readonly array $filter,
        public readonly array $affectedRooms,
        public readonly array $paceRooms,
        public readonly array $safetyOnlyRooms,
        public readonly array $protectNow,
        public readonly array $orphanRooms,
        public readonly DateRange $orphanWindow,
        public readonly array $deferredKeys,
    ) {
    }

    public function continues(): bool
    {
        return $this->triggers !== [];
    }

    /**
     * What every light run leaves to the next full run.
     *
     * @return list<DeferredWork>
     */
    public static function alwaysDeferred(): array
    {
        return [
            DeferredWork::PaceWithoutSale,
            DeferredWork::RelationsSweep,
            DeferredWork::StayBeyondWindow,
            DeferredWork::Anchors,
            DeferredWork::ListingSettings,
            DeferredWork::WeeklyReview,
        ];
    }
}
