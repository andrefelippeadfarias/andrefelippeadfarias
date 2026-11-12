<?php

declare(strict_types=1);

namespace Ritmo\Domain\Plan;

use InvalidArgumentException;
use Ritmo\Domain\Model\RoomType;
use Ritmo\Domain\Support\Num;

/**
 * Converts plan items from currency to what the pricing system stores: whole percentages over
 * the base of the listing. A floor rounds UP (the floor applied is never under the amount
 * asked), a ceiling rounds DOWN (never over it) and is never left under the floor.
 *
 * An item whose percentages are the ones already in force only differs by rounding and is
 * dropped: writing it would change nothing.
 *
 * Reference: the percentages of validar_plano.montar_payload (piso_pct, teto_pct).
 */
final class PercentRounding
{
    public function convert(PlanItem $item, RoomType $room): PercentItem
    {
        if ($item->roomCode !== $room->code) {
            throw new InvalidArgumentException(sprintf('The item is for room "%s", not "%s".', $item->roomCode, $room->code));
        }

        $min = $item->minPrice === null ? null : Num::floorToPercent($item->minPrice, $room->base);
        $max = $item->maxPrice === null ? null : Num::ceilingToPercent($item->maxPrice, $room->base);
        if ($min !== null && $max !== null && $max < $min) {
            $max = $min;
        }

        return new PercentItem($item, $room->base, $min, $max);
    }

    /**
     * @param list<RoomType> $rooms the room types of the plan's property
     *
     * @throws InvalidArgumentException when an item is for a room that is not in the list
     */
    public function apply(ValidatedPlan $plan, array $rooms): PercentPlan
    {
        $byCode = [];
        foreach ($rooms as $room) {
            $byCode[$room->code] = $room;
        }

        $items = [];
        $dropped = [];
        foreach ($plan->plan->items as $item) {
            if (!isset($byCode[$item->roomCode])) {
                throw new InvalidArgumentException(sprintf('Unknown room "%s" in the plan.', $item->roomCode));
            }
            $percent = $this->convert($item, $byCode[$item->roomCode]);
            if ($percent->sameAsInForce()) {
                $dropped[] = $percent;
            } else {
                $items[] = $percent;
            }
        }

        return new PercentPlan($plan, $items, $dropped);
    }
}
