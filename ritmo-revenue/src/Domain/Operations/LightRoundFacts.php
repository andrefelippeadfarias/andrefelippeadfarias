<?php

declare(strict_types=1);

namespace Ritmo\Domain\Operations;

use DateTimeImmutable;
use InvalidArgumentException;
use Ritmo\Domain\Support\Days;

/**
 * What a light run knows when it scopes its work: the movement, the sold-out flips of the
 * inventory read now, the nights sold out now, the manual changes found, the new overbooked
 * nights and, per room, whether the confirmation recalculation still fits the quota.
 */
final class LightRoundFacts
{
    public readonly DateTimeImmutable $today;

    /**
     * @param int                                   $horizonDays     nights from today the plan covers (today included)
     * @param list<Movement>                        $movements
     * @param list<SoldOutFlip>                     $flips           flips from today to today + max(protect, orphan) days
     * @param array<string, true>                   $soldOutNow      PlanItem keys of the nights sold out in the inventory read now
     * @param array<string, list<DateTimeImmutable>> $manualChanges  room code => nights changed by hand (frozen)
     * @param int                                   $newOverbooked   overbooked nights not alerted before
     * @param array<string, bool>                   $quota           room code => the confirmation recalculation fits the quota
     */
    public function __construct(
        DateTimeImmutable $today,
        public readonly int $horizonDays,
        public readonly array $movements,
        public readonly array $flips,
        public readonly array $soldOutNow,
        public readonly array $manualChanges,
        public readonly int $newOverbooked,
        public readonly array $quota,
    ) {
        if ($horizonDays < 1) {
            throw new InvalidArgumentException('The horizon of a light run covers at least today.');
        }
        $this->today = Days::normalize($today);
    }
}
