<?php

declare(strict_types=1);

namespace Ritmo\Domain\Pricing\Ladder;

use Ritmo\Domain\Model\RoomType;
use Ritmo\Domain\Support\Num;

/**
 * The amount the pricing system really applies for a floor or a ceiling: it stores them as a
 * whole percentage of the listing base, so a floor is rounded up and a ceiling down to the next
 * whole percentage (two decimals). Room relations, ladder limits and the no-effect test compare
 * these values, never the raw amounts.
 *
 * Reference: pace.efetivo.
 */
final class EffectiveValue
{
    private function __construct()
    {
    }

    public static function floor(float $amount, RoomType $room): float
    {
        return Num::percentToAmount((float) Num::floorToPercent($amount, $room->base), $room->base, 2);
    }

    public static function ceiling(float $amount, RoomType $room): float
    {
        return Num::percentToAmount((float) Num::ceilingToPercent($amount, $room->base), $room->base, 2);
    }
}
