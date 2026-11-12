<?php

declare(strict_types=1);

namespace Ritmo\Domain\Pricing\Protection;

use Ritmo\Domain\Model\DateOverride;
use Ritmo\Domain\Model\PriceNight;
use Ritmo\Domain\Pricing\RuleInForce;

/**
 * The floor and ceiling in force on a night, in money: the value the pricing calendar reports
 * when it is above zero; otherwise the one of the live date override (a percentage over the
 * listing base, two decimals, or a fixed amount); otherwise unknown (null).
 *
 * A summarised calendar read carries no floor or ceiling, so the override is the fallback.
 *
 * Reference: pace.vigente.
 */
final class LiveBounds
{
    private function __construct()
    {
    }

    public static function floor(?PriceNight $night, ?DateOverride $override, float $base): ?float
    {
        $value = $night?->minPrice;

        return $value !== null && $value > 0 ? $value : RuleInForce::floor($override, $base);
    }

    public static function ceiling(?PriceNight $night, ?DateOverride $override, float $base): ?float
    {
        $value = $night?->maxPrice;

        return $value !== null && $value > 0 ? $value : RuleInForce::ceiling($override, $base);
    }
}
