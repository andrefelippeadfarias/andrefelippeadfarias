<?php

declare(strict_types=1);

namespace Ritmo\Application\Run;

use Ritmo\Application\Schedule\LightRoundRequest;
use Ritmo\Application\Schedule\LightRoundService;
use Ritmo\Application\ShadowRun\RunContext;
use Ritmo\Application\Write\Quota\RecalcGuard;
use Ritmo\Domain\Model\InventoryNight;
use Ritmo\Domain\Model\RoomType;
use Ritmo\Domain\Operations\LightTrigger;
use Ritmo\Domain\Operations\Movement;
use Ritmo\Domain\Write\ThirdParty\ThirdPartyChange;
use Ritmo\Infrastructure\Db\Repository\RunRepository;

/**
 * What a light run may touch (operations spec 4.4): the nights of the bookings that moved, the
 * sold-out flips of the first nights and the nights changed by hand; a room whose listing has no
 * recalculation left gets only safety items. Without a trigger the filter is empty: the run
 * decides nothing and its report says so in three lines.
 */
final class LightScope
{
    public function __construct(
        private readonly LightRoundService $service,
        private readonly RecalcGuard $quota,
        private readonly RunRepository $runs,
        private readonly QuotaRunStart $runStart,
    ) {
    }

    /**
     * @param list<Movement>                               $movements
     * @param array<string, array<string, InventoryNight>> $inventory room code => night => night read by this run
     * @param list<ThirdPartyChange>                       $changes   nights changed by someone else, found by this run
     *
     * @return array{filter: list<string>, summary: array<string, mixed>}
     */
    public function scope(RunContext $context, array $movements, array $inventory, array $changes, int $newOverbooked): array
    {
        $startedAt = $this->runs->find($context->runId)['started_at'] ?? $context->capturedAt() ?? $context->today;
        $manual = [];
        foreach ($changes as $change) {
            $manual[$change->roomCode][] = $change->night;
        }
        $listings = [];
        foreach ($context->rooms as $room) {
            $listings[$room->code] = $room->pricingRef;
        }
        $plan = $this->service->plan(new LightRoundRequest(
            $context->property->id,
            array_map(static fn (RoomType $r): string => $r->code, $context->rooms),
            $context->today,
            $context->horizon->count(),
            $movements,
            $inventory,
            $context->capturedAt() ?? $startedAt,
            $manual,
            $newOverbooked,
            $context->rules->lightRun(),
        ), new RecalcRoomQuota($this->quota, $listings, $this->runStart->of($context)), $context->runId);

        $filter = $plan->continues() ? array_keys($plan->filter) : [];

        return [
            'filter' => $filter,
            'summary' => [
                'triggers' => array_map(static fn (LightTrigger $t): string => $t->value, $plan->triggers),
                'nights' => count($filter),
                'pace_rooms' => $plan->paceRooms,
                'safety_only_rooms' => $plan->safetyOnlyRooms,
            ],
        ];
    }
}
