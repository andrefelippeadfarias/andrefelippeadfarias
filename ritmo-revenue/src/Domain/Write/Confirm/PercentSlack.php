<?php

declare(strict_types=1);

namespace Ritmo\Domain\Write\Confirm;

use InvalidArgumentException;

/**
 * How far a recalculated price may sit from a floor or ceiling written as a whole percentage of
 * the base and still count as obeying it: 1% of the larger of the value and the base plus one
 * unit, never less than 1.5% of the value nor than one unit. The payload rounds a floor up to a
 * whole percentage of the base, so the error reaches 1% of the base.
 *
 * Reference: ler_recalculo.tolerancia_percentual.
 */
final class PercentSlack
{
    private function __construct()
    {
    }

    /**
     * @throws InvalidArgumentException on a value that is not finite
     */
    public static function of(float $value, float $base): float
    {
        if (!is_finite($value) || !is_finite($base)) {
            throw new InvalidArgumentException('The slack needs finite amounts.');
        }

        return max(0.015 * $value, 0.01 * max($value, $base) + 1.0, 1.0);
    }
}
