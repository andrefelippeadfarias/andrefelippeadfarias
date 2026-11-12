<?php

declare(strict_types=1);

namespace Ritmo\Domain\Calendar\Stay;

/**
 * The minimum stay a night must have, and whether it is the one-night stay of an orphan night.
 * minStay is null where no stay rule decides the night.
 */
final class PlannedStay
{
    public function __construct(
        public readonly ?int $minStay,
        public readonly bool $orphan = false,
    ) {
    }

    public static function undecided(): self
    {
        return new self(null);
    }
}
