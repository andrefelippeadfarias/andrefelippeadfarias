<?php

declare(strict_types=1);

namespace Ritmo\Application\Rules\Calendar;

use InvalidArgumentException;
use Ritmo\Domain\Support\Days;
use DateTimeImmutable;

/**
 * Gregorian Easter Sunday (the anonymous Gregorian algorithm, also known as Meeus/Jones/Butcher),
 * from which the movable holidays are counted (Carnival, Good Friday, Corpus Christi).
 */
final class Easter
{
    private function __construct()
    {
    }

    /**
     * @throws InvalidArgumentException outside the years 1583 to 4099
     */
    public static function sunday(int $year): DateTimeImmutable
    {
        if ($year < 1583 || $year > 4099) {
            throw new InvalidArgumentException('Easter is computed for the years 1583 to 4099.');
        }
        $a = $year % 19;
        $b = intdiv($year, 100);
        $c = $year % 100;
        $d = intdiv($b, 4);
        $e = $b % 4;
        $f = intdiv($b + 8, 25);
        $g = intdiv($b - $f + 1, 3);
        $h = (19 * $a + $b - $d - $g + 15) % 30;
        $i = intdiv($c, 4);
        $k = $c % 4;
        $l = (32 + 2 * $e + 2 * $i - $h - $k) % 7;
        $m = intdiv($a + 11 * $h + 22 * $l, 451);
        $month = intdiv($h + $l - 7 * $m + 114, 31);
        $day = (($h + $l - 7 * $m + 114) % 31) + 1;

        return Days::date(sprintf('%04d-%02d-%02d', $year, $month, $day));
    }
}
