<?php

declare(strict_types=1);

namespace Ritmo\Domain\Stay\Write;

/**
 * The PMS changes after pairing, with the plan items that now carry their pushed values (the
 * merged items, some extended or added), and the changes no pricing step can cover.
 */
final class PairingResult
{
    /**
     * @param list<PmsChangeEntry> $entries   every change, paired or holding (unpaired ones too)
     * @param MergeResult          $items     the plan items with the pairs folded in
     * @param list<PmsChangeEntry> $unpaired  pushed changes neither paired nor held (refused pms_unpaired)
     */
    public function __construct(
        public readonly array $entries,
        public readonly MergeResult $items,
        public readonly array $unpaired,
    ) {
    }
}
