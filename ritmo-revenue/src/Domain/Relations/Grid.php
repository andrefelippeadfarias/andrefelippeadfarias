<?php

declare(strict_types=1);

namespace Ritmo\Domain\Relations;

use Ritmo\Domain\Model\RoomType;
use Ritmo\Domain\Support\Num;

/**
 * The percent grid of a room: floors and ceilings go to the pricing system as whole percents of
 * the listing base, so relations compare the amounts the pricing system will really apply.
 */
final class Grid
{
    /** Whole percent of a floor (rounded up). */
    public static function floorPercent(RoomType $room, float $amount): int
    {
        return Num::floorToPercent($amount, $room->base);
    }

    /** Whole percent of a ceiling (rounded down). */
    public static function ceilingPercent(RoomType $room, float $amount): int
    {
        return Num::ceilingToPercent($amount, $room->base);
    }

    /** The amount of a whole percent, in cents. */
    public static function amount(RoomType $room, int $percent): float
    {
        return Num::percentToAmount((float) $percent, $room->base, 2);
    }

    /** What the pricing system applies for a floor of this amount. */
    public static function effective(RoomType $room, float $amount): float
    {
        return self::amount($room, self::floorPercent($room, $amount));
    }

    /** What the pricing system applies for a ceiling of this amount. */
    public static function effectiveDown(RoomType $room, float $amount): float
    {
        return self::amount($room, self::ceilingPercent($room, $amount));
    }
}
