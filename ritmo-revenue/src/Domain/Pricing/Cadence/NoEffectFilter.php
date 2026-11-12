<?php

declare(strict_types=1);

namespace Ritmo\Domain\Pricing\Cadence;

use InvalidArgumentException;
use Ritmo\Domain\Model\DateOverride;
use Ritmo\Domain\Model\PriceNight;
use Ritmo\Domain\Model\RoomType;
use Ritmo\Domain\Plan\PlanItem;
use Ritmo\Domain\Pricing\Ladder\EffectiveValue;
use Ritmo\Domain\Pricing\Protection\LiveBounds;
use Ritmo\Domain\Support\Num;

/**
 * Drops the items that would change nothing, before they are validated, written or recorded as
 * steps: writing them costs a write and the ledger would record a step that never happened,
 * holding the real step of the day.
 *
 * No effect (reference pace.sem_efeito): an item with a ceiling, not on a night deleted first and
 * without a minimum stay, whose greatest effective value (floor rounded up, ceiling rounded down
 * to a whole percentage of the base) is within the money slack of both the live floor and the
 * live ceiling. An item with a floor below its ceiling is tested with its greatest value (kept
 * quirk of the reference).
 *
 * Rounding only (a lesson of the reference's operation, not in its code; off in the parity preset): an item, not
 * on a night deleted first and without a minimum stay, whose floor lands on the same whole
 * percentage of the base as the live floor and whose ceiling (when it sets one) on the same as the
 * live ceiling. Both live values it compares must be known.
 */
final class NoEffectFilter
{
    public function __construct(
        private readonly bool $dropRoundingOnly = true,
        private readonly float $moneySlack = 1.0,
    ) {
    }

    /**
     * @param list<PlanItem>              $items
     * @param array<string, RoomType>     $rooms       by room code
     * @param array<string, PriceNight>   $live        calendar nights by PlanItem::keyOf()
     * @param array<string, DateOverride> $overrides   live date overrides by PlanItem::keyOf()
     * @param array<string, true>         $deleteFirst keys of the nights whose override is deleted first
     *
     * @throws InvalidArgumentException when an item names a room that is not given
     */
    public function filter(array $items, array $rooms, array $live, array $overrides, array $deleteFirst = []): NoEffectResult
    {
        $kept = $noEffect = $roundingOnly = [];
        foreach ($items as $item) {
            $room = $rooms[$item->roomCode] ?? throw new InvalidArgumentException(sprintf('Unknown room type "%s".', $item->roomCode));
            $key = $item->key();
            if (isset($deleteFirst[$key]) || $item->minStay !== null) {
                $kept[] = $item;
                continue;
            }
            $floor = LiveBounds::floor($live[$key] ?? null, $overrides[$key] ?? null, $room->base);
            $ceiling = LiveBounds::ceiling($live[$key] ?? null, $overrides[$key] ?? null, $room->base);
            if ($this->hasNoEffect($item, $room, $floor, $ceiling)) {
                $noEffect[] = $item;
            } elseif ($this->dropRoundingOnly && $this->isRoundingOnly($item, $room, $floor, $ceiling)) {
                $roundingOnly[] = $item;
            } else {
                $kept[] = $item;
            }
        }

        return new NoEffectResult($kept, $noEffect, $roundingOnly);
    }

    private function hasNoEffect(PlanItem $item, RoomType $room, ?float $floor, ?float $ceiling): bool
    {
        if ($item->maxPrice === null || $floor === null || $ceiling === null) {
            return false;
        }
        $value = EffectiveValue::ceiling($item->maxPrice, $room);
        if ($item->minPrice !== null) {
            $value = max(EffectiveValue::floor($item->minPrice, $room), $value);
        }

        return abs($value - $floor) <= $this->moneySlack && abs($value - $ceiling) <= $this->moneySlack;
    }

    private function isRoundingOnly(PlanItem $item, RoomType $room, ?float $floor, ?float $ceiling): bool
    {
        if ($item->minPrice !== null && ($floor === null || Num::floorToPercent($item->minPrice, $room->base) !== Num::floorToPercent($floor, $room->base))) {
            return false;
        }
        if ($item->maxPrice !== null && ($ceiling === null || Num::ceilingToPercent($item->maxPrice, $room->base) !== Num::ceilingToPercent($ceiling, $room->base))) {
            return false;
        }

        return $item->minPrice !== null || $item->maxPrice !== null;
    }
}
