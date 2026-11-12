<?php

declare(strict_types=1);

namespace Ritmo\Domain\Pricing\Ladder;

/**
 * The last-minute drop of a ladder: inside the window before the night, with less than a share
 * of the units sold, the price drops by a percentage (applied after the steps and the ceiling).
 */
final class DropRule
{
    public function __construct(
        public readonly bool $enabled = false,
        public readonly int $windowDays = 0,
        public readonly float $occupancyBelow = 0.0,
        public readonly float $percent = 0.0,
        public readonly bool $skipInsideWindowAtActivation = true,
    ) {
    }
}
