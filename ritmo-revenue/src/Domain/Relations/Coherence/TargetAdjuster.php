<?php

declare(strict_types=1);

namespace Ritmo\Domain\Relations\Coherence;

use DateTimeImmutable;
use Ritmo\Domain\Relations\Grid;
use Ritmo\Domain\Support\Num;

/**
 * Moves the floor and ceiling of a target room-night for a chain relation (at least, between,
 * premium). A rule already met never creates an item; a floor only rises and a ceiling only falls,
 * except that a ceiling below a raised floor is lifted to it.
 *
 * The price the following relations see for the target is its effective floor.
 */
final class TargetAdjuster
{
    public function __construct(private readonly CoherenceContext $context)
    {
    }

    public function apply(string $room, DateTimeImmutable $night, ?float $floor, ?float $ceiling, ?string $orderRef, bool $forceOrder, Reason $reason): Outcome
    {
        $blocked = $this->blocked($room, $night);
        if ($blocked !== null) {
            return $blocked;
        }
        $facts = $this->context->facts;
        $current = (float) $facts->price($room, $night);
        $item = $this->context->draft->get($room, $night);
        $tolerance = $this->context->settings->triggerAmount;
        if ($item === null && ($floor === null || $current >= $floor - $tolerance) && ($ceiling === null || $current <= $ceiling + $tolerance)) {
            return Outcome::Satisfied;
        }
        $item = $this->itemFor($room, $night, $item, $orderRef, $forceOrder);
        if ($floor !== null) {
            $this->raiseFloor($item, $floor, $ceiling === null);
        }
        if ($ceiling !== null && ($item->ceiling() === null || $ceiling < $item->ceiling())) {
            $item->setCeiling((float) Num::floorInt(max($ceiling, $item->floor() ?? 0.0)));
            if ($item->floor() === null) {
                $inForce = $facts->floorInForce($room, $night) ?? $ceiling;
                $item->setFloor(min((float) Num::floorInt($inForce), (float) $item->ceiling()));
            }
        }
        $item->addReason($reason);
        $effectiveFloor = $item->floor();
        $facts->decide($room, $night, $effectiveFloor !== null && $effectiveFloor > 0.0 ? Grid::effective($facts->room($room), $effectiveFloor) : $current);

        return Outcome::Applied;
    }

    private function blocked(string $room, DateTimeImmutable $night): ?Outcome
    {
        $orders = $this->context->hooks->orders;
        if ($orders->isFrozen($room, $night)) {
            return Outcome::SkippedFrozen;
        }
        if ($orders->hasOwnerPrice($room, $night)) {
            return Outcome::SkippedOwnerPrice;
        }

        return $this->context->chainFree($room, $night) ? null : Outcome::SkippedSoldOut;
    }

    /**
     * The item of the room-night, created when missing, citing the right order: a forced order
     * replaces the item's; otherwise the floor order of the night wins.
     */
    private function itemFor(string $room, DateTimeImmutable $night, ?DraftItem $item, ?string $orderRef, bool $forceOrder): DraftItem
    {
        $floorOrder = $forceOrder ? null : $this->context->hooks->orders->floorOrderRef($room, $night);
        if ($forceOrder && $item !== null) {
            $item->citeOrder($orderRef);
        }
        if ($floorOrder !== null) {
            $orderRef = $floorOrder;
        }
        if ($item === null) {
            $row = $this->context->facts->row($room, $night);
            $item = new DraftItem($room, $night, (float) $row?->price, null, null, $orderRef);
            $this->context->draft->add($item);
        } elseif ($floorOrder !== null && $item->orderRef() !== $floorOrder) {
            $item->citeOrder($floorOrder);
        }

        return $item;
    }

    private function raiseFloor(DraftItem $item, float $floor, bool $noCeilingAsked): void
    {
        if ($floor <= ($item->floor() ?? 0.0)) {
            return;
        }
        $room = $this->context->facts->room($item->room);
        $item->setFloor((float) Num::ceilInt($floor));
        if (Grid::effective($room, (float) $item->floor()) < $floor - 0.01) {
            $item->setFloor((float) Num::ceilInt(Grid::effective($room, $floor)));
        }
        if ($item->ceiling() !== null && $item->ceiling() < $item->floor()) {
            $item->setCeiling((float) $item->floor());
        }
        if ($noCeilingAsked && $item->ceiling() === null && $this->context->hooks->strategy->pinsFloorToCeiling($item->room, $item->night)) {
            $item->setCeiling((float) $item->floor());
        }
    }
}
