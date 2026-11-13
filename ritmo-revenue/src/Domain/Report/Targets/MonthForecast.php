<?php

declare(strict_types=1);

namespace Ritmo\Domain\Report\Targets;

/**
 * The forecast of one month for the whole site: per scenario, the formula over the properties
 * plus the typed adjustment, never below what is already on the books.
 */
final class MonthForecast
{
    /**
     * @param array<string, int> $scenarios scenario value => forecast
     * @param list<string>       $missing   properties without last year's market inputs (left out of the sum)
     */
    public function __construct(
        public readonly string $month,
        public readonly float $onBooks,
        public readonly array $scenarios,
        public readonly array $missing,
    ) {
    }

    public function of(Scenario $scenario): int
    {
        return $this->scenarios[$scenario->value];
    }
}
