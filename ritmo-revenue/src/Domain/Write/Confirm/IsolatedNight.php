<?php

declare(strict_types=1);

namespace Ritmo\Domain\Write\Confirm;

use DateTimeImmutable;
use Ritmo\Domain\Model\PriceNight;
use Ritmo\Domain\Support\Days;

/**
 * A free night between two sold-out nights. The pricing system shows a minimum stay of one there
 * by its own orphan-day rule whatever the date rule says, so a stay that differs on such a night
 * is a warning, never a reason to write again.
 *
 * Reference: ler_recalculo.dia_isolado.
 */
final class IsolatedNight
{
    private function __construct()
    {
    }

    /**
     * @param array<string, PriceNight> $calendar the recalculated nights of the room by date ("YYYY-MM-DD")
     */
    public static function is(array $calendar, DateTimeImmutable $night): bool
    {
        $own = $calendar[Days::key($night)] ?? null;

        return $own !== null
            && !$own->isSoldOut()
            && self::soldOut($calendar, Days::add($night, -1))
            && self::soldOut($calendar, Days::add($night, 1));
    }

    /**
     * @param array<string, PriceNight> $calendar
     */
    private static function soldOut(array $calendar, DateTimeImmutable $night): bool
    {
        return ($calendar[Days::key($night)] ?? null)?->isSoldOut() === true;
    }
}
