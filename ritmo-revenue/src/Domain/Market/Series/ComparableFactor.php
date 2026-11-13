<?php

declare(strict_types=1);

namespace Ritmo\Domain\Market\Series;

/**
 * The factor that turns a competitor's cheapest-room price into the price of its comparable room
 * (comparable price / cheapest price).
 *
 * Reference: faixas_dono.r_hidro. A competitor without a factor (or with zero) takes the default
 * factor; an estimated factor above the cap is capped, because an estimate that high is more
 * likely a wrong reading than a real gap.
 */
final class ComparableFactor
{
    public const MEASURED = 'measured';

    public const ESTIMATED = 'estimated';

    private function __construct()
    {
    }

    /**
     * @param float|null $factor    the competitor's factor; null or 0 = not set
     * @param string     $source    self::MEASURED, self::ESTIMATED or '' (unknown)
     * @param float      $default   market.comparable.default_factor
     * @param float      $cap       market.comparable.estimated_factor_cap
     */
    public static function of(?float $factor, string $source, float $default, float $cap): float
    {
        $value = $factor === null || $factor == 0.0 ? $default : $factor;

        return $source === self::ESTIMATED && $value > $cap ? $cap : $value;
    }
}
