<?php

declare(strict_types=1);

namespace Ritmo\Application\Schedule;

use Ritmo\Application\Alerts\AlertService;
use Ritmo\Domain\Alerts\AlertCode;
use Ritmo\Domain\Alerts\AlertRecord;
use Ritmo\Domain\Alerts\OperationAlerts;
use Ritmo\Domain\Operations\LightRoundFacts;
use Ritmo\Domain\Operations\LightRoundPlan;
use Ritmo\Domain\Operations\LightRoundPlanner;
use Ritmo\Domain\Operations\SoldOutFlips;
use Ritmo\Domain\Plan\PlanItem;
use Ritmo\Domain\Support\Days;
use Ritmo\Infrastructure\Db\Repository\InventorySnapshotRepository;

/**
 * Scopes a light run (operations spec 4.4 to 4.7): compares the inventory read now with the
 * latest read before this run (sold-out flips), asks the quota which rooms can take a pace step
 * (the confirmation recalculation after writing must fit), and builds the plan of
 * {@see LightRoundPlanner}. A room without quota gets only safety entries and the warning
 * recalc_quota_exhausted; the warning resolves when the room has quota again.
 */
final class LightRoundService
{
    public function __construct(
        private readonly InventorySnapshotRepository $snapshots,
        private readonly AlertService $alerts,
    ) {
    }

    public function plan(LightRoundRequest $request, RoomQuota $quota, ?int $runId = null): LightRoundPlan
    {
        $config = $request->config;
        $window = max($config->protectFlipDays, $config->orphanWindowDays);
        $last = Days::add($request->today, $window);
        $before = $request->readStartedAt->modify('-1 second');
        $previous = [];
        foreach ($request->rooms as $room) {
            $previous[$room] = $this->snapshots->latest($room, $request->today, $last, $before);
        }

        $plan = (new LightRoundPlanner($config))->plan(new LightRoundFacts(
            $request->today,
            $request->horizonDays,
            $request->movements,
            SoldOutFlips::find($request->inventory, $previous, $request->today, $window),
            $this->soldOutNow($request),
            $request->manualChanges,
            $request->newOverbooked,
            array_combine($request->rooms, array_map(static fn (string $room): bool => $quota->permits($room), $request->rooms)),
        ));

        $raised = $this->alerts->raiseAll(array_map(
            static fn (string $room) => OperationAlerts::recalcQuotaExhausted($request->propertyId, $room),
            $plan->safetyOnlyRooms
        ), $runId);
        $this->alerts->settle(
            $request->propertyId,
            [AlertCode::RecalcQuotaExhausted],
            $raised,
            static fn (AlertRecord $a): bool => in_array($a->roomCode, $plan->paceRooms, true),
        );

        return $plan;
    }

    /**
     * @return array<string, true> PlanItem keys of the nights of the horizon sold out now
     */
    private function soldOutNow(LightRoundRequest $request): array
    {
        $first = Days::key($request->today);
        $last = Days::key(Days::add($request->today, $request->horizonDays - 1));
        $keys = [];
        foreach ($request->inventory as $room => $nights) {
            foreach ($nights as $key => $night) {
                if ($key >= $first && $key <= $last && $night->available <= 0) {
                    $keys[PlanItem::keyOf((string) $room, $night->date)] = true;
                }
            }
        }

        return $keys;
    }
}
