<?php

declare(strict_types=1);

namespace Ritmo\Domain\Report\Targets;

use InvalidArgumentException;

/**
 * Scenario parameters of the revenue forecast (section reports.forecast): per scenario a growth
 * over last year's market and an index per property (how the property sells against the market);
 * the growth of "today" and "realistic"; the maximum index per property; the high-season months
 * and the season minimums used when a month has no typed minimum. Neutral: growth 1, index 1.
 */
final class ForecastConfig
{
    private const GROWTH = [0.5, 2.0];
    private const INDEX = [0.1, 5.0];

    /**
     * @param array<string, float>                $growth          scenario value => growth
     * @param array<string, array<string, float>> $index           scenario value => property id => index
     * @param array<string, float>                $maximumIndex    property id => index of the maximum
     * @param list<int>                           $highMonths      month numbers (1-12) of the high season
     * @param array<string, array<string, float>> $seasonMinimum   'high'|'low' => property id => minimum
     *
     * @throws InvalidArgumentException on values outside their ranges
     */
    public function __construct(
        public readonly array $growth = [],
        public readonly array $index = [],
        public readonly float $realisticGrowth = 1.0,
        public readonly array $maximumIndex = [],
        public readonly array $highMonths = [],
        public readonly array $seasonMinimum = [],
    ) {
        foreach ([...array_values($growth), $realisticGrowth] as $g) {
            self::range($g, self::GROWTH, 'growth');
        }
        foreach ([...array_merge([], ...array_values(array_map('array_values', $index))), ...array_values($maximumIndex)] as $i) {
            self::range($i, self::INDEX, 'index');
        }
        foreach ($highMonths as $month) {
            if (!is_int($month) || $month < 1 || $month > 12) {
                throw new InvalidArgumentException('High-season months are month numbers from 1 to 12.');
            }
        }
    }

    public function growth(Scenario $scenario): float
    {
        return $this->growth[$scenario->value] ?? 1.0;
    }

    public function index(Scenario $scenario, string $propertyId): float
    {
        return $this->index[$scenario->value][$propertyId] ?? 1.0;
    }

    public function maximumIndex(string $propertyId): float
    {
        return $this->maximumIndex[$propertyId] ?? 1.0;
    }

    public function isHigh(int $month): bool
    {
        return in_array($month, $this->highMonths, true);
    }

    public function seasonMinimum(int $month, string $propertyId): ?float
    {
        return $this->seasonMinimum[$this->isHigh($month) ? 'high' : 'low'][$propertyId] ?? null;
    }

    /**
     * @param array{0: float, 1: float} $range
     */
    private static function range(mixed $value, array $range, string $what): void
    {
        if (!is_float($value) && !is_int($value) || $value < $range[0] || $value > $range[1]) {
            throw new InvalidArgumentException(sprintf('A forecast %s must be between %s and %s.', $what, $range[0], $range[1]));
        }
    }
}
