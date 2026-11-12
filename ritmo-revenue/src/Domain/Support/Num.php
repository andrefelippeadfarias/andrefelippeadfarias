<?php

declare(strict_types=1);

namespace Ritmo\Domain\Support;

use InvalidArgumentException;

/**
 * The only place where the domain rounds numbers.
 *
 * The rule engine was first written in Python and its results are frozen in golden fixtures,
 * so every rounding here reproduces Python 3 bit for bit:
 *
 * - {@see roundHalfEven()} is Python's round(): correctly rounded on the exact binary value of
 *   the float, ties to even (round(0.5) = 0, round(2.5) = 2, round(2.675, 2) = 2.67).
 * - {@see ceilEps()} / {@see floorEps()} are math.ceil(x - 1e-9) / math.floor(x + 1e-9): they
 *   forgive the float noise of a value that is an integer "on paper" (14.000000000000002).
 * - Floors expressed as a percentage over a base are rounded UP (the effective floor is never
 *   below the amount asked) and ceilings DOWN (never above it).
 */
final class Num
{
    /** Tolerance used by ceilEps() and floorEps(), the same 1e-9 of the reference engine. */
    public const EPSILON = 1e-9;

    private const MAX_DECIMALS = 15;

    /**
     * Python 3's round($value, $decimals) for floats.
     *
     * sprintf('%.{n}F') is the same correctly rounded, ties-to-even conversion CPython uses
     * (dtoa mode 3 on the exact binary value). PHP's own round() pre-rounds and differs on
     * values such as 2.675 or 1.005.
     *
     * @param int $decimals 0 to 15
     *
     * @throws InvalidArgumentException when the value is not finite or the decimals are out of range
     */
    public static function roundHalfEven(float $value, int $decimals = 0): float
    {
        self::assertFinite($value);
        if ($decimals < 0 || $decimals > self::MAX_DECIMALS) {
            throw new InvalidArgumentException(sprintf('Decimals must be between 0 and %d.', self::MAX_DECIMALS));
        }

        // "+ 0.0" turns the negative zero of round(-0.4) into a plain zero.
        return (float) sprintf('%.' . $decimals . 'F', $value) + 0.0;
    }

    /**
     * Python 3's round($value) as an integer.
     *
     * @throws InvalidArgumentException when the value is not finite or does not fit in an integer
     */
    public static function roundInt(float $value): int
    {
        return self::toInt(self::roundHalfEven($value, 0));
    }

    /**
     * math.ceil($value - 1e-9): rounds up, ignoring float noise just above an integer.
     *
     * @throws InvalidArgumentException when the value is not finite or does not fit in an integer
     */
    public static function ceilEps(float $value): int
    {
        self::assertFinite($value);

        return self::toInt(ceil($value - self::EPSILON));
    }

    /**
     * math.floor($value + 1e-9): rounds down, ignoring float noise just below an integer.
     *
     * @throws InvalidArgumentException when the value is not finite or does not fit in an integer
     */
    public static function floorEps(float $value): int
    {
        self::assertFinite($value);

        return self::toInt(floor($value + self::EPSILON));
    }

    /**
     * Limits a value to [$min, $max].
     *
     * @throws InvalidArgumentException when a value is not finite or $min is greater than $max
     */
    public static function clamp(float $value, float $min, float $max): float
    {
        self::assertFinite($value);
        self::assertFinite($min);
        self::assertFinite($max);
        if ($min > $max) {
            throw new InvalidArgumentException('clamp(): the minimum is greater than the maximum.');
        }

        return max($min, min($max, $value));
    }

    /**
     * A FLOOR amount as a whole percentage over a base, rounded UP so that the amount the
     * pricing system derives from the percentage is never below the amount asked.
     *
     * -31 means base x 0.69. Reference: validar_plano.piso_pct.
     *
     * @throws InvalidArgumentException when the base is not positive or a value is not finite
     */
    public static function floorToPercent(float $amount, float $base): int
    {
        return self::ceilEps(self::percentOverBase($amount, $base));
    }

    /**
     * A CEILING amount as a whole percentage over a base, rounded DOWN so that the amount the
     * pricing system derives from the percentage is never above the amount asked.
     *
     * Reference: validar_plano.teto_pct.
     *
     * @throws InvalidArgumentException when the base is not positive or a value is not finite
     */
    public static function ceilingToPercent(float $amount, float $base): int
    {
        return self::floorEps(self::percentOverBase($amount, $base));
    }

    /**
     * The amount a percentage over a base stands for: base x (1 + percent / 100), rounded half
     * to even to the given decimals.
     *
     * With 0 decimals this is the whole amount the pricing system applies (reference:
     * validar_plano.em_reais). With 2 decimals it is the "effective" value used when comparing
     * floors and ceilings (reference: pace.efetivo).
     *
     * @throws InvalidArgumentException when the base is not positive or a value is not finite
     */
    public static function percentToAmount(float $percent, float $base, int $decimals = 0): float
    {
        self::assertFinite($percent);
        self::assertPositiveBase($base);

        return self::roundHalfEven($base * (1 + $percent / 100), $decimals);
    }

    /**
     * The exact (unrounded) percentage of an amount over a base: amount / base x 100 - 100.
     * The order of the operations is the reference engine's and must not change.
     */
    private static function percentOverBase(float $amount, float $base): float
    {
        self::assertFinite($amount);
        self::assertPositiveBase($base);

        return $amount / $base * 100 - 100;
    }

    private static function assertFinite(float $value): void
    {
        if (!is_finite($value)) {
            throw new InvalidArgumentException('Expected a finite number.');
        }
    }

    private static function assertPositiveBase(float $base): void
    {
        self::assertFinite($base);
        if ($base <= 0) {
            throw new InvalidArgumentException('The base must be greater than zero.');
        }
    }

    private static function toInt(float $whole): int
    {
        if ($whole >= (float) PHP_INT_MAX || $whole <= (float) PHP_INT_MIN) {
            throw new InvalidArgumentException('The number does not fit in an integer.');
        }

        return (int) $whole;
    }
}
