<?php

declare(strict_types=1);

namespace Ritmo\Domain\Report\Targets;

use InvalidArgumentException;

/**
 * A holiday of one property (table holiday_targets): the room nights of its window, what is sold
 * (room nights and revenue) and, per scenario, the target occupancy (a fraction) and daily rate.
 */
final class HolidayTarget
{
    /**
     * @param array<string, float> $occupancy scenario value => target occupancy (0-1)
     * @param array<string, float> $adr       scenario value => daily rate
     *
     * @throws InvalidArgumentException on values outside their ranges or a missing scenario
     */
    public function __construct(
        public readonly string $period,
        public readonly int $roomNights,
        public readonly int $soldNights,
        public readonly float $soldRevenue,
        public readonly array $occupancy,
        public readonly array $adr,
    ) {
        if ($roomNights < 0 || $soldNights < 0 || $soldRevenue < 0) {
            throw new InvalidArgumentException('Room nights and revenue cannot be negative.');
        }
        foreach (Scenario::cases() as $scenario) {
            $o = $occupancy[$scenario->value] ?? null;
            $a = $adr[$scenario->value] ?? null;
            if (!is_float($o) && !is_int($o) || $o < 0 || $o > 1 || !is_float($a) && !is_int($a) || $a < 0) {
                throw new InvalidArgumentException('Each scenario needs an occupancy from 0 to 1 and a daily rate.');
            }
        }
    }

    /**
     * The scenarios whose daily rate is below the holiday floor of the rule set (refused when saved).
     *
     * @return list<Scenario>
     */
    public function belowFloor(float $floor): array
    {
        return array_values(array_filter(Scenario::cases(), fn (Scenario $s): bool => $this->adr[$s->value] < $floor));
    }
}
