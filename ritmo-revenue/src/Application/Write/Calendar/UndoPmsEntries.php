<?php

declare(strict_types=1);

namespace Ritmo\Application\Write\Calendar;

use Ritmo\Domain\Calendar\Stay\PricingSync;
use Ritmo\Domain\Calendar\Stay\StaySyncCapability;
use Ritmo\Domain\Calendar\Stay\StaySyncField;
use Ritmo\Domain\Plan\PlanItem;
use Ritmo\Domain\Stay\Write\PmsChangeEntry;
use Ritmo\Domain\Support\Days;
use Ritmo\Domain\Write\Change\PmsRestore;
use Ritmo\Domain\Write\State\ChangeField;
use Ritmo\Domain\Write\State\NightFields;

/**
 * The PMS restores of an undo (UndoPlanner, M3) as PMS changes for the check, the approval and
 * the gate (spec 06, 3.9): reason "undo", the fields read now as "before", and, for a field the
 * pricing system pushes, the pricing item of the same undo that restores it there (else the check
 * refuses it pms_unpaired: written alone, the next push would undo it).
 */
final class UndoPmsEntries
{
    public function __construct(
        private readonly PricingSync $sync,
        private readonly StaySyncCapability $capability,
    ) {
    }

    /**
     * @param list<PmsRestore>                  $restores
     * @param array<string, array<string, NightFields>> $readNow room => night => PMS fields read now
     * @param list<PlanItem>                    $pricingUndo the pricing items of the same undo
     *
     * @return list<PmsChangeEntry>
     */
    public function of(array $restores, array $readNow, array $pricingUndo): array
    {
        $pricing = [];
        foreach ($pricingUndo as $item) {
            $pricing[$item->key()] = true;
        }
        $out = [];
        foreach ($restores as $restore) {
            $key = PlanItem::keyOf($restore->roomCode, $restore->night);
            $entry = PmsChangeEntry::fromRestore($restore, $readNow[$restore->roomCode][Days::key($restore->night)] ?? NightFields::none());
            $pushed = $this->pushed($restore);
            $entry = new PmsChangeEntry($entry->roomCode, $entry->night, $entry->target, $entry->before, $entry->reason, $pushed, null, false, $pushed && isset($pricing[$key]) ? $key : null, $entry->undoOf);
            $out[] = $entry;
        }

        return $out;
    }

    private function pushed(PmsRestore $restore): bool
    {
        $fields = $restore->fields;

        return ($fields->has(ChangeField::PMS_MIN_STAY) && $this->sync->pushes(StaySyncField::MinStay, $restore->roomCode, $this->capability))
            || ($fields->has(ChangeField::PMS_MAX_STAY) && $this->sync->pushes(StaySyncField::MaxStay, $restore->roomCode, $this->capability))
            || ($fields->has(ChangeField::PMS_RESTRICTION) && $this->sync->pushes(StaySyncField::ArrivalDeparture, $restore->roomCode, $this->capability));
    }
}
