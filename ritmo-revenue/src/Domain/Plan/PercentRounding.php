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
 * A plan is only converted with the listing bases it was validated with: percentages of another
 * base are amounts the Validator never checked.
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
     * @param list<RoomType> $rooms the room types of the plan's property, as they are now
     *
     * @throws InvalidArgumentException when the plan was not sealed by the Validator, when an item
     *                                  is for a room that is not in the list, or when the base of
     *                                  a room is not the one the plan was validated with
     */
    public function apply(ValidatedPlan $plan, array $rooms): PercentPlan
    {
        if (!Validator::sealed($plan)) {
            throw new InvalidArgumentException('The plan was not sealed by the Validator.');
        }
        $byCode = [];
        foreach ($rooms as $room) {
            $byCode[$room->code] = $room;
        }
        foreach ($plan->bases as $code => $base) {
            if (isset($byCode[$code]) && $byCode[$code]->base !== $base) {
                throw new InvalidArgumentException(sprintf('The base of room "%s" is %.2f, the plan was validated with %.2f: validate it again.', $code, $byCode[$code]->base, $base));
            }
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
