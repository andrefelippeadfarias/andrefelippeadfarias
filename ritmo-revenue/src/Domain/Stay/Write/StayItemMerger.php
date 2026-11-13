<?php

declare(strict_types=1);

namespace Ritmo\Domain\Stay\Write;

use Ritmo\Domain\Plan\PlanItem;

/**
 * Merges the stay items into the price items, one item per room night (spec 06, 3.4 step 3): a
 * field the price item does not set is taken from the stay item; the same field with a different
 * value takes the stay item's (it read the effective stay; decision SW-D3) and the room night is
 * reported as a conflict. The orphan claim and "keep the reason" come with the stay values.
 */
final class StayItemMerger
{
    private function __construct()
    {
    }

    /**
     * @param list<PlanItem> $priceItems
     * @param list<PlanItem> $stayItems
     */
    public static function merge(array $priceItems, array $stayItems): MergeResult
    {
        $byKey = [];
        foreach ($priceItems as $item) {
            $byKey[$item->key()] = $item;
        }
        $conflicts = [];
        foreach ($stayItems as $stay) {
            $key = $stay->key();
            $price = $byKey[$key] ?? null;
            if ($price === null) {
                $byKey[$key] = $stay;
                continue;
            }
            if (self::differs($price->minStay, $stay->minStay) || self::differs($price->checkInMask, $stay->checkInMask) || self::differs($price->checkOutMask, $stay->checkOutMask)) {
                $conflicts[] = $key;
            }
            $byKey[$key] = self::combine($price, $stay);
        }

        return new MergeResult(array_values($byKey), $conflicts);
    }

    private static function differs(int|string|null $a, int|string|null $b): bool
    {
        return $a !== null && $b !== null && $a !== $b;
    }

    private static function combine(PlanItem $price, PlanItem $stay): PlanItem
    {
        $setsStay = $stay->minStay !== null || $stay->checkInMask !== null || $stay->checkOutMask !== null;

        return new PlanItem(
            $price->roomCode,
            $price->date,
            $price->minPrice,
            $price->maxPrice,
            $stay->minStay ?? $price->minStay,
            $price->reasonCode,
            $price->reasonParams + ['stay_reason' => $stay->reasonCode],
            $price->before,
            $stay->checkInMask ?? $price->checkInMask,
            $stay->checkOutMask ?? $price->checkOutMask,
            $price->orderId,
            $setsStay && $stay->orphan !== null ? $stay->orphan : $price->orphan,
            $price->marketEvidence,
            $price->keepReason && $stay->keepReason,
            $price->reasonText,
            $price->readsOccupancy || $stay->readsOccupancy,
        );
    }
}
