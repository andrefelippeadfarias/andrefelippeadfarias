<?php

declare(strict_types=1);

namespace Ritmo\Domain\Pricing;

use Ritmo\Domain\Model\DateOverride;
use Ritmo\Domain\Support\Num;

/**
 * The floor and ceiling of the date rule in force for a night, and the price of the night
 * brought within them.
 *
 * A price read before the last rule was written (an old recalculation of the pricing system, or
 * the price the PMS still holds) does not know that rule: the pricing system will apply it. So
 * the engine works from the price the rule allows: under the floor it is the floor, over the
 * ceiling it is the ceiling (never under the floor).
 *
 * Reference: pace.preco_no_vigente with pace.vigente over the date rule only.
 */
final class RuleInForce
{
    /** A price within this distance of a bound is on it (the reference's margin). */
    public const MARGIN = 0.5;

    private function __construct()
    {
    }

    /**
     * The floor of the rule as an amount: the fixed amount, else the percentage over the listing
     * base (two decimals); null without a rule or a floor.
     */
    public static function floor(?DateOverride $rule, float $base): ?float
    {
        return self::amount($rule?->minFixed, $rule?->minPercent, $base);
    }

    /**
     * The ceiling of the rule as an amount, like floor().
     */
    public static function ceiling(?DateOverride $rule, float $base): ?float
    {
        return self::amount($rule?->maxFixed, $rule?->maxPercent, $base);
    }

    /**
     * The price within the floor and the ceiling in force. A price within MARGIN of where it
     * would go is left as it is.
     */
    public static function price(float $price, ?float $floor, ?float $ceiling): float
    {
        $within = $price;
        if ($floor !== null && $within < $floor - self::MARGIN) {
            $within = $floor;
        }
        if ($ceiling !== null && $within > $ceiling + self::MARGIN) {
            $within = max($ceiling, $floor ?? 0.0);
        }

        return abs($within - $price) >= self::MARGIN ? $within : $price;
    }

    private static function amount(?float $fixed, ?float $percent, float $base): ?float
    {
        if ($fixed !== null) {
            return $fixed;
        }

        return $percent === null ? null : Num::percentToAmount($percent, $base, 2);
    }
}
