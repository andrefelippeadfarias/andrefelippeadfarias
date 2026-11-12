<?php

declare(strict_types=1);

namespace Ritmo\Domain\Market;

use InvalidArgumentException;

/**
 * Quantiles by linear interpolation between the closest ranks.
 *
 * Reference: ancora_mercado.quantil and Python's statistics.median. The order of the float
 * operations is the reference's and must not change.
 */
final class Quantile
{
    /**
     * The value at the fraction $p (0 to 1) of the sorted values. An empty list has no quantile:
     * the result is NAN, as in the reference.
     *
     * @param list<int|float> $values in any order
     *
     * @throws InvalidArgumentException when $p is outside 0 to 1 or a value is not finite
     */
    public static function of(array $values, float $p): float
    {
        if (!is_finite($p) || $p < 0.0 || $p > 1.0) {
            throw new InvalidArgumentException('The quantile fraction must be between 0 and 1.');
        }
        $sorted = self::sorted($values);
        $count = count($sorted);
        if ($count === 0) {
            return NAN;
        }

        $rank = ($count - 1) * $p;
        $index = (int) $rank;
        $fraction = $rank - $index;
        if ($index + 1 >= $count) {
            return $sorted[$index];
        }

        return $sorted[$index] + ($sorted[$index + 1] - $sorted[$index]) * $fraction;
    }

    /**
     * The median: the middle value, or the mean of the two middle values.
     *
     * @param list<int|float> $values in any order
     *
     * @throws InvalidArgumentException when the list is empty or a value is not finite
     */
    public static function median(array $values): float
    {
        $sorted = self::sorted($values);
        $count = count($sorted);
        if ($count === 0) {
            throw new InvalidArgumentException('The median of an empty list is undefined.');
        }

        $middle = intdiv($count, 2);
        if ($count % 2 === 1) {
            return $sorted[$middle];
        }

        return ($sorted[$middle - 1] + $sorted[$middle]) / 2;
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
                throw new InvalidArgumentException('Quantiles need finite numbers.');
            }
            $sorted[] = (float) $value;
        }
        sort($sorted);

        return $sorted;
    }
}
