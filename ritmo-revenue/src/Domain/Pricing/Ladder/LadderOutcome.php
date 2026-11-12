<?php

declare(strict_types=1);

namespace Ritmo\Domain\Pricing\Ladder;

/**
 * The item a ladder room night produces (or null) and its line for the grid.
 */
final class LadderOutcome
{
    public function __construct(
        public readonly ?LadderItem $item,
        public readonly LadderLine $line,
    ) {
    }
}
