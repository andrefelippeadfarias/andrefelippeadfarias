<?php

declare(strict_types=1);

namespace Ritmo\Domain\Pricing\Ladder;

use DateTimeImmutable;
use Ritmo\Domain\Pricing\StepDirection;
use Ritmo\Domain\Support\Days;

/**
 * The diagnosis of a ladder room night for the grid, written whether or not an item comes out.
 */
final class LadderLine
{
    public const TODAY_NEVER_UP = 'LADDER_TODAY_NEVER_UP';
    public const OUT_OF_NIGHT_FILTER = 'OUT_OF_NIGHT_FILTER';

    public readonly DateTimeImmutable $night;

    /**
     * @param int         $targetPublic ladder price, public plan, rounded
     * @param int         $targetSent   ladder price, sent plan, rounded
     * @param int         $new          the floor the item would write
     * @param string|null $hold         why no item came out although the price moved (cadence, filter, today)
     */
    public function __construct(
        public readonly string $roomCode,
        DateTimeImmutable $night,
        public readonly int $daysOut,
        public readonly string $nightType,
        public readonly int $sold,
        public readonly int $units,
        public readonly int $soldPercent,
        public readonly int $targetPublic,
        public readonly int $targetSent,
        public readonly float $live,
        public readonly int $livePublic,
        public readonly int $new,
        public readonly ?StepDirection $direction,
        public readonly ?string $hold = null,
    ) {
        $this->night = Days::normalize($night);
    }

    public function withHold(string $hold): self
    {
        return new self(
            $this->roomCode,
            $this->night,
            $this->daysOut,
            $this->nightType,
            $this->sold,
            $this->units,
            $this->soldPercent,
            $this->targetPublic,
            $this->targetSent,
            $this->live,
            $this->livePublic,
            $this->new,
            $this->direction,
            $hold,
        );
    }
}
