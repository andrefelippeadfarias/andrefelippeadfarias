<?php

declare(strict_types=1);

namespace Ritmo\Domain\Operations;

use DateTimeImmutable;
use Ritmo\Domain\Calendar\Stay\ArrivalDepartureChange;
use Ritmo\Domain\Calendar\Stay\InventoryAudit;
use Ritmo\Domain\Calendar\Stay\StayBatch;
use Ritmo\Domain\Calendar\Stay\StayChange;
use Ritmo\Domain\Model\InventoryNight;
use Ritmo\Domain\Plan\PlanItem;

/**
 * The PMS side of the orphan-night stays of a light run: when a night near today sold out or
 * reopened, the stays and arrival restrictions of that room are audited again over the orphan
 * window with the inventory read now (never the morning's: L 09/11, a cancellation reopening a
 * Friday left the Saturday sellable alone until the next morning), so the closed-day orphan side
 * opens and closes back with the stay. Entries flagged alsoPricing go to the pricing system too
 * (STAY.md). A frozen night (manual change) gets nothing.
 *
 * Reference: SKILL.md (light routine "--so-estadia", plus the audit when the flipped night is a
 * closed day or the night before it), calendario_12m.lote_unico.
 */
final class OrphanWindow
{
    public function __construct(
        private readonly InventoryAudit $audit,
        private readonly StayBatch $batch,
    ) {
    }

    /**
     * @param array<string, array<string, InventoryNight>> $inventory room code => 'YYYY-MM-DD' => night, read now
     * @param array<string, true>                          $frozen    PlanItem keys of frozen nights
     *
     * @return list<StayChange|ArrivalDepartureChange> in the batch order
     */
    public function changes(array $inventory, LightRoundPlan $plan, DateTimeImmutable $today, ?DateTimeImmutable $readAt, array $frozen = []): array
    {
        if ($plan->orphanRooms === []) {
            return [];
        }
        $rooms = array_fill_keys($plan->orphanRooms, true);
        $audits = $this->audit->audit(array_intersect_key($inventory, $rooms), $plan->orphanWindow->first(), $plan->orphanWindow->last(), $today, $readAt);

        return array_values(array_filter(
            $this->batch->single($audits, $today, false),
            static fn (StayChange|ArrivalDepartureChange $c): bool => isset($rooms[$c->roomCode])
                && $plan->orphanWindow->contains($c->night)
                && !isset($frozen[PlanItem::keyOf($c->roomCode, $c->night)])
        ));
    }
}
