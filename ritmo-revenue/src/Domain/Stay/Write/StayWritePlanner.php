<?php

declare(strict_types=1);

namespace Ritmo\Domain\Stay\Write;

use Ritmo\Domain\Calendar\Stay\ArrivalDepartureChange;
use Ritmo\Domain\Calendar\Stay\ChangeTarget;
use Ritmo\Domain\Calendar\Stay\InventoryAudit;
use Ritmo\Domain\Calendar\Stay\PricingStayPlan;
use Ritmo\Domain\Calendar\Stay\StayBatch;
use Ritmo\Domain\Calendar\Stay\StayChange;
use Ritmo\Domain\Plan\PlanItem;
use Ritmo\Domain\Support\Days;

/**
 * Plans the stay writes of a run (spec 06, 3.4): the PMS changes of the inventory audit in the
 * single batch's order (none on a frozen night), the pricing stay items (the stay-only plan unless
 * stay.write.raise_own_stays), merged into the price items one per room night, and every pushed
 * PMS change paired with a pricing item or held by the pricing calendar.
 */
final class StayWritePlanner
{
    public function __construct(
        private readonly StayWriteConfig $config,
        private readonly InventoryAudit $audit,
        private readonly StayBatch $batch,
        private readonly PricingStayPlan $pricing,
        private readonly StayPairing $pairing,
    ) {
    }

    public function plan(StayWriteInputs $in): StayWritePlan
    {
        if (!$this->config->enabled) {
            return StayWritePlan::off($in->priceItems);
        }
        $changes = $this->batch->single($this->audit->audit($in->inventory, $in->from, $in->to, $in->today, $in->readAt), $in->today, false);
        $entries = $frozenOut = $targets = [];
        foreach ($changes as $change) {
            if ($change->target !== ChangeTarget::Pms) {
                continue;
            }
            $entry = PmsChangeEntry::fromStay($change);
            $targets[$entry->identity()] = true;
            if (isset($in->frozen[self::nightKey($change)])) {
                $frozenOut[] = $entry;
            } else {
                $entries[] = $entry;
            }
        }
        $merged = StayItemMerger::merge($in->priceItems, $this->stayItems($in));
        $paired = $this->pairing->pair($entries, $merged, $in->calendar, $in->dateRules, $in->lastPricingNight);

        return new StayWritePlan($paired->entries, $paired->items->items, $paired->items->conflicts, $targets, $frozenOut);
    }

    /**
     * @return list<PlanItem>
     */
    private function stayItems(StayWriteInputs $in): array
    {
        $last = min($in->to, $in->lastPricingNight);
        $items = [];
        foreach ($in->calendar as $room => $calendar) {
            $occupancy = $in->occupancy[$room] ?? null;
            if ($occupancy === null) {
                continue;
            }
            $entries = $this->pricing->plan((string) $room, $calendar, $occupancy, $in->from, $last, $in->today, $in->effective[$room] ?? null, $in->dateRules[$room] ?? null, !$this->config->raiseOwnStays);
            foreach ($entries as $entry) {
                $item = StayItemFactory::toPlanItem($entry);
                if ($item !== null && !isset($in->frozen[$room . '|' . Days::key($entry->night)])) {
                    $items[] = $item;
                }
            }
        }

        return $items;
    }

    private static function nightKey(StayChange|ArrivalDepartureChange $change): string
    {
        return $change->roomCode . '|' . Days::key($change->night);
    }
}
