<?php

declare(strict_types=1);

namespace Ritmo\Domain\Relations\Coherence;

use DateTimeImmutable;
use LogicException;
use Ritmo\Domain\Model\RoomType;
use Ritmo\Domain\Relations\Grid;
use Ritmo\Domain\Relations\Params\BandParams;
use Ritmo\Domain\Relations\Params\SwitchAction;
use Ritmo\Domain\Relations\Params\SwitchParams;
use Ritmo\Domain\Relations\Relation;
use Ritmo\Domain\Support\Num;

/**
 * When the sold units of the trigger set reach the threshold, the target changes rule: it goes to
 * the market price on its percent grid (never below the switch floor and, while a member of the
 * capping band is free, never above the band's upper edge) or keeps its own price. While the
 * switch holds, the relations it suspends do not run on that night.
 *
 * When the target is already at the switched price (current price, floor and ceiling in force all
 * within one grid step), nothing is written and the outcome says so explicitly
 * ({@see Outcome::SwitchSatisfied}), so that the validator accepts the night without an item.
 */
final class OccupancySwitchRule implements CoherenceRule
{
    public function __construct(private readonly CoherenceContext $context)
    {
    }

    public function evaluate(Relation $relation, DateTimeImmutable $night): Outcome
    {
        $params = $relation->params;
        if (!$params instanceof SwitchParams) {
            throw new LogicException('An occupancy_switch relation needs switch parameters.');
        }
        $facts = $this->context->facts;
        $target = $relation->target;
        if (!$facts->isFreeUnblocked($target, $night)) {
            return Outcome::SkippedTargetUnavailable;
        }
        [$sold, $units] = OccupancyCount::of($facts, $this->context->graph->triggerMembers($relation), $night);
        if (!$params->isHit($sold, $units)) {
            return Outcome::NotTriggered;
        }
        if ($params->action === SwitchAction::Hold) {
            return Outcome::SwitchSatisfied;
        }
        $market = $this->context->hooks->market->sentPrice($relation, $target, $night);
        if ($market === null) {
            $this->context->warn(RelationWarning::SWITCH_NO_MARKET_DATA, $relation, $night, ['sold' => $sold, 'units' => $units]);

            return Outcome::NoMarketData;
        }
        $room = $facts->room($target);
        [$new, $cap] = $this->switchedPrice($relation, $params, $night, $room, $market);
        $orders = $this->context->hooks->orders;
        if ($orders->isFrozen($target, $night) || $orders->hasOwnerPrice($target, $night)) {
            return Outcome::SwitchHeldByOwner;
        }
        if ($this->alreadyThere($relation, $night, $room, $new)) {
            return Outcome::SwitchSatisfied;
        }
        $item = $this->context->draft->get($target, $night);
        if ($item === null) {
            $item = new DraftItem($target, $night, (float) $facts->row($target, $night)?->price);
            $this->context->draft->add($item);
        }
        $item->putReasonFirst(new Reason(Reason::OCCUPANCY_SWITCH, $relation->id, ['sold' => $sold, 'units' => $units, 'market' => $market, 'cap' => $cap]));
        $item->pin($new);
        $item->citeOrder($relation->orderRef);
        $facts->decide($target, $night, $new);

        return Outcome::SwitchApplied;
    }

    /**
     * The switched price on the target's grid, and the cap that applied (null when none).
     *
     * @return array{float, float|null}
     */
    private function switchedPrice(Relation $relation, SwitchParams $params, DateTimeImmutable $night, RoomType $room, float $market): array
    {
        $floor = $this->context->hooks->floors->switchFloor($relation->target, $night);
        $percentLo = Grid::floorPercent($room, $floor);
        $percent = max(Grid::floorPercent($room, $market), $percentLo);
        $cap = null;
        $band = $params->capRelation === null ? null : $this->context->graph->relation($params->capRelation);
        if ($band !== null && $band->params instanceof BandParams) {
            $refs = BandReferences::free($this->context, $band, $night);
            if ($refs !== []) {
                $cap = (float) Num::floorInt(min($refs) * $band->params->upper());
                $percent = max(min($percent, Grid::ceilingPercent($room, $cap)), $percentLo);
            }
        }

        return [Grid::amount($room, $percent), $cap];
    }

    private function alreadyThere(Relation $relation, DateTimeImmutable $night, RoomType $room, float $new): bool
    {
        $facts = $this->context->facts;
        if ($this->context->draft->get($relation->target, $night) !== null) {
            return false;
        }
        $slack = $this->context->settings->auditTolerance($room);
        $current = $facts->decided($relation->target, $night) ?? (float) $facts->row($relation->target, $night)?->price;
        $floor = $facts->floorInForce($relation->target, $night);
        $ceiling = $facts->ceilingInForce($relation->target, $night);

        return abs($current - $new) <= $slack
            && $floor !== null && abs($floor - $new) <= $slack
            && $ceiling !== null && abs($ceiling - $new) <= $slack;
    }
}
