<?php

declare(strict_types=1);

namespace Ritmo\Connectors\PriceLabs;

use Ritmo\Connectors\Support\Payload;
use Ritmo\Domain\Model\PriceNight;

/**
 * Turns one night of the PriceLabs calendar into a PriceNight.
 *
 * The API marks "no value" with -1 (a booked single unit has user_price -1), reports the
 * occupancy of a listing with several units as text ("3/7") and, for a single unit, only says
 * whether it is booked.
 */
final class PriceNightMapper
{
    /**
     * @param array<int|string, mixed> $night
     *
     * @return PriceNight|null null for a night without a date or a price
     */
    public function map(array $night): ?PriceNight
    {
        $date = Payload::date($night, 'date');
        $price = Payload::number($night, 'price');
        if ($date === null || $price === null || $price < 0) {
            return null;
        }

        [$sold, $total] = self::occupancy($night);

        return new PriceNight(
            $date,
            $price,
            Payload::positive($night, 'min_price'),
            Payload::positive($night, 'max_price'),
            Payload::count($night, 'min_stay'),
            $sold,
            $total,
            false,
            Payload::positive($night, 'user_price'),
            Payload::positive($night, 'uncustomized_price'),
            Payload::flag($night, 'unbookable')
        );
    }

    /**
     * Units sold and units of the listing. Without the "sold/total" text the listing is a
     * single unit, sold when the night has a booking status.
     *
     * @param array<int|string, mixed> $night
     *
     * @return array{int, int}
     */
    private static function occupancy(array $night): array
    {
        if (preg_match('#^\s*(\d{1,6})\s*/\s*(\d{1,6})\s*$#', Payload::text($night, 'multi_unit_occupancy', 32), $match) === 1) {
            return [(int) $match[1], (int) $match[2]];
        }

        return [Payload::text($night, 'booking_status', 64) === '' ? 0 : 1, 1];
    }
}
