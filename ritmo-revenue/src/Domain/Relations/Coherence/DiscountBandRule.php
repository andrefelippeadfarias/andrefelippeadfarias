<?php

declare(strict_types=1);

namespace Ritmo\Domain\Relations\Coherence;

use DateTimeImmutable;
use LogicException;
use Ritmo\Domain\Model\RoomType;
use Ritmo\Domain\Relations\Grid;
use Ritmo\Domain\Relations\Params\BandParams;
use Ritmo\Domain\Relations\Params\WriteMode;
use Ritmo\Domain\Relations\Relation;
use Ritmo\Domain\Support\Num;

/**
 * The target between two percents of the cheapest free member of its set, chosen directly on the
 * target's percent grid so that the payload cannot move it out of the band.
 *
 * An item of the target (its pace step) only chooses the point inside the band; a night without
 * an item is touched only outside [lower, upper + one grid step], so that rounding never rewrites
 * it. On the down-only night types the band never raises the target and stops at its own floor.
 * The target's floor wins over the upper edge (with a warning).
 */
final class DiscountBandRule implements CoherenceRule
{
    public function __construct(private readonly CoherenceContext $context)
    {
    }

    public function evaluate(Relation $relation, DateTimeImmutable $night): Outcome
    {
        $params = $relation->params;
        if (!$params instanceof BandParams) {
            throw new LogicException('A discount_band relation needs band parameters.');
        }
        $facts = $this->context->facts;
        $target = $relation->target;
        if (!$facts->isFreeUnblocked($target, $night)) {
            return Outcome::SkippedTargetUnavailable;
        }
        $refs = BandReferences::forBand($this->context, $relation, $params, $night);
        if ($refs === []) {
            return Outcome::EmptySet;
        }
        $reference = min($refs);
        $hi = (float) Num::floorInt($reference * $params->upper());
        $ownFloor = $this->ownFloor($relation, $params, $night, $hi);
        $lo = max(Num::ceilInt($params->lowerRatio() * $params->upper() * ($reference + $params->referenceRoundingMargin)) + $params->lowerExtraAmount, (float) Num::ceilInt($ownFloor));
        $item = $this->dropStepOnLadderNight($relation, $params, $night);
        $current = $facts->price($target, $night);
        $room = $facts->room($target);
        $downOnly = $params->isDownOnly($this->context->nightType($night));
        $percentLo = Grid::floorPercent($room, $lo);
        $percentHi = Grid::ceilingPercent($room, $hi);
        $percent = $this->choose($params, $relation, $night, $item, $current, $room, $hi, $percentLo, $percentHi);
        if ($downOnly) {
            $percent = max($percentHi, Grid::floorPercent($room, $ownFloor));
        }
        $new = Grid::amount($room, $percent);

        $held = $this->heldBack($params, $reference, $hi, $item, $current, $room, $new, $downOnly);
        if ($held !== null) {
            return $held;
        }
        if ($this->context->hooks->orders->isFrozen($target, $night)) {
            return Outcome::SkippedFrozen;
        }
        if ($this->context->hooks->orders->hasOwnerPrice($target, $night)) {
            return Outcome::SkippedOwnerPrice;
        }
        if ($percentLo > $percentHi) {
            $this->context->warn(RelationWarning::FLOOR_ABOVE_BAND, $relation, $night, ['floor' => $lo, 'upper_edge' => $hi]);
        }
        $this->write($relation, $params, $night, $item, $room, $new, $downOnly ? Grid::floorPercent($room, $ownFloor) : $percentLo, $downOnly ? $percent : max($percentHi, $percentLo), $reference);

        return Outcome::Applied;
    }

    /**
     * The target's floor tonight; a floor set by an owner order above the upper edge gives way to
     * the lowest floor the room still has.
     */
    private function ownFloor(Relation $relation, BandParams $params, DateTimeImmutable $night, float $hi): float
    {
        $floors = $this->context->hooks->floors;
        $inForce = $floors->inForce($relation->target, $night);
        if ($params->orderFloorYields && $inForce->fromOrder && $inForce->value > $hi) {
            $lowest = $floors->lowestAllowed($relation->target, $night);
            $this->context->warn(RelationWarning::ORDER_FLOOR_YIELDED, $relation, $night, ['order_floor' => $inForce->value, 'floor' => $lowest]);

            return $lowest;
        }

        return $inForce->value;
    }

    /**
     * On a night a member is priced by the ladder, the target's own pace step is discarded.
     */
    private function dropStepOnLadderNight(Relation $relation, BandParams $params, DateTimeImmutable $night): ?DraftItem
    {
        $item = $this->context->draft->get($relation->target, $night);
        if ($item === null || !$this->followsLadder($relation, $params, $night) || !$item->engineStep || $item->strategyItem) {
            return $item;
        }
        $this->context->draft->remove($item);
        $this->context->facts->forget($relation->target, $night);
        $this->context->warn(RelationWarning::ENGINE_STEP_DISCARDED, $relation, $night);

        return null;
    }

    private function followsLadder(Relation $relation, BandParams $params, DateTimeImmutable $night): bool
    {
        if (!$params->followStrategyReference) {
            return false;
        }
        foreach ($this->context->graph->members($relation) as $member) {
            if ($this->context->facts->isStrategyDecided($member, $night)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The percent inside the band: the item's floor (or the current price) clamped to the band;
     * the upper edge on a ladder night of the reference.
     */
    private function choose(BandParams $params, Relation $relation, DateTimeImmutable $night, ?DraftItem $item, ?float $current, RoomType $room, float $hi, int $percentLo, int $percentHi): int
    {
        $base = $item?->floor() ?? $current;
        if ($this->followsLadder($relation, $params, $night)) {
            $base = $hi;
        }

        return min(max(Grid::floorPercent($room, (float) $base), $percentLo), max($percentHi, $percentLo));
    }

    /**
     * Why nothing is written although the band was computed, or null to write.
     */
    private function heldBack(BandParams $params, float $reference, float $hi, ?DraftItem $item, ?float $current, RoomType $room, float $new, bool $downOnly): ?Outcome
    {
        $settings = $this->context->settings;
        $okLo = $params->lowerRatio() * $params->upper() * $reference;
        $okHi = $reference * $params->upper() + $settings->auditBasePercent / 100 * $room->base + $settings->auditAmount;
        if ($item === null && $current !== null && ($downOnly ? $current <= $okHi : ($okLo <= $current && $current <= $okHi))) {
            return Outcome::Satisfied;
        }
        if ($downOnly && $item !== null && ($item->floor() ?: ($current ?? 0.0)) <= $hi + $settings->triggerAmount) {
            return Outcome::SatisfiedDownOnly;
        }
        if ($downOnly && $new >= ($current ?? 0.0) - $settings->triggerAmount) {
            return Outcome::FloorBlocksLowering;
        }

        return null;
    }

    private function write(Relation $relation, BandParams $params, DateTimeImmutable $night, ?DraftItem $item, RoomType $room, float $new, int $boundsFloor, int $boundsCeiling, float $reference): void
    {
        if ($item === null) {
            $row = $this->context->facts->row($relation->target, $night);
            $item = new DraftItem($relation->target, $night, (float) $row?->price, null, null, $relation->orderRef);
            $this->context->draft->add($item);
        }
        $item->addReason(new Reason(Reason::DISCOUNT_BAND, $relation->id, ['upper_percent' => $params->upperPercent, 'reference' => $reference]));
        if ($params->writeMode === WriteMode::Bounds) {
            $item->setFloor(Grid::amount($room, $boundsFloor));
            $item->setCeiling(Grid::amount($room, max($boundsCeiling, $boundsFloor)));
            $new = (float) $item->floor();
        } else {
            $item->pin($new);
        }
        if ($item->orderRef() === null) {
            $item->citeOrder($relation->orderRef);
        }
        $this->context->facts->decide($relation->target, $night, $new);
    }
}
