<?php

declare(strict_types=1);

namespace Ritmo\Domain\Pricing\Ladder;

/**
 * Inside a ladder night, a room kept between two other rooms: never below the cheaper one and
 * at most a position of the way up to the dearer one.
 */
final class BetweenRule
{
    public function __construct(
        public readonly string $room,
        public readonly string $low,
        public readonly string $high,
        public readonly float $position,
    ) {
    }
}
