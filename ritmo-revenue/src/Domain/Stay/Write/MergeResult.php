<?php

declare(strict_types=1);

namespace Ritmo\Domain\Stay\Write;

use Ritmo\Domain\Plan\PlanItem;

/**
 * The plan items after the stay items were merged in: one per room night, and the room nights
 * where the stay item's value replaced a different one of the price item.
 */
final class MergeResult
{
    /**
     * @param list<PlanItem> $items
     * @param list<string>   $conflicts plan item keys
     */
    public function __construct(public readonly array $items, public readonly array $conflicts)
    {
    }

    public function item(string $key): ?PlanItem
    {
        foreach ($this->items as $item) {
            if ($item->key() === $key) {
                return $item;
            }
        }

        return null;
    }
}
