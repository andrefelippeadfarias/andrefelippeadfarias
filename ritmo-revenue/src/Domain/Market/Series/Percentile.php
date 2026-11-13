<?php

declare(strict_types=1);

namespace Ritmo\Domain\Market\Series;

use InvalidArgumentException;

/**
 * The percentile p (0 to 100) of a list of prices, by linear interpolation between the closest
 * ranks.
 *
 * Reference: faixas_dono.quantil. The rank is (count - 1) * p / 100, in this order of operations
 * (the M1 anchor's Quantile::of multiplies by a fraction instead, which can differ in the last
 * bit), so the values match the reference before any rounding.
 */
final class Percentile
{
    private function __construct()
    {
    }

    /**
     * @param list<int|float> $values in any order
     * @param int|float       $p      0 to 100
     *
     * @throws InvalidArgumentException on an empty list, a value that is not finite or p outside 0 to 100
     */
    public static function of(array $values, int|float $p): float
    {
        if (!is_finite((float) $p) || $p < 0 || $p > 100) {
            throw new InvalidArgumentException('A percentile is between 0 and 100.');
        }
        $sorted = self::sorted($values);
        $count = count($sorted);
        if ($count === 0) {
            throw new InvalidArgumentException('A percentile needs at least one value.');
        }
        $rank = ($count - 1) * $p / 100;
        $index = (int) $rank;
        $next = min($index + 1, $count - 1);

        return $sorted[$index] + ($sorted[$next] - $sorted[$index]) * ($rank - $index);
    }

    /**
     * Python's statistics.median: the middle value, or the mean of the two middle values.
     *
     * @param list<int|float> $values in any order
     *
     * @throws InvalidArgumentException on an empty list or a value that is not finite
     */
    public static function median(array $values): float
    {
        $sorted = self::sorted($values);
        $count = count($sorted);
        if ($count === 0) {
            throw new InvalidArgumentException('The median of an empty list is undefined.');
        }
        $middle = intdiv($count, 2);

        return $count % 2 === 1 ? $sorted[$middle] : ($sorted[$middle - 1] + $sorted[$middle]) / 2;
    }

    /**
     * @param list<int|float> $values
     *
     * @return list<float> ascending
     */
    private static function sorted(array $values): array
    {
        $sorted = [];
        foreach ($values as $value) {
            if ((!is_int($value) && !is_float($value)) || !is_finite((float) $value)) {
                throw new InvalidArgumentException('Percentiles need finite numbers.');
            }
            $sorted[] = (float) $value;
        }
        sort($sorted);

        return $sorted;
    }
}
