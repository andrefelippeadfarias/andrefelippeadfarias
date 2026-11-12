<?php

declare(strict_types=1);

namespace Ritmo\Domain\Pricing\Ladder;

/**
 * The last-minute market floor of a strategy: on the nights the ladder does not price, close to
 * the night, the floor of some rooms comes down to a market value when that is lower.
 */
final class LastMinuteFloorRule
{
    /**
     * @param list<string>         $nightTypes       ruler night-type labels it covers
     * @param list<int>            $excludedWeekdays weekdays never covered (Monday = 0)
     * @param array<string, float> $rooms            room code => multiplier of the market value
     */
    public function __construct(
        public readonly bool $enabled = false,
        public readonly array $nightTypes = [],
        public readonly array $excludedWeekdays = [],
        public readonly bool $skipHolidays = true,
        public readonly int $windowDays = 7,
        public readonly ?string $series = null,
        public readonly float $percentile = 50.0,
        public readonly array $rooms = [],
    ) {
    }
}
