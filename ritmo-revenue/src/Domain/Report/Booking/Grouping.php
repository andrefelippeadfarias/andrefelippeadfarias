<?php

declare(strict_types=1);

namespace Ritmo\Domain\Report\Booking;

use Closure;

/**
 * Groups booking lines by a key and totals each group, keys in ascending order.
 */
final class Grouping
{
    private function __construct()
    {
    }

    /**
     * @param list<BookingLine>                $lines
     * @param Closure(BookingLine): (int|string) $key
     *
     * @return array<int|string, BookingTotals>
     */
    public static function totals(array $lines, Closure $key): array
    {
        $groups = [];
        foreach ($lines as $line) {
            $groups[$key($line)][] = $line;
        }
        ksort($groups, SORT_STRING);

        return array_map(static fn (array $group): BookingTotals => BookingTotals::of($group), $groups);
    }
}
