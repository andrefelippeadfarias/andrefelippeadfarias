<?php

declare(strict_types=1);

namespace Ritmo\Domain\Report\Occupancy;

use DateTimeImmutable;
use Ritmo\Domain\Model\InventoryNight;
use Ritmo\Domain\Model\PriceNight;
use Ritmo\Domain\Model\RoomType;
use Ritmo\Domain\Support\Days;

/**
 * The next nights room by room (RP-3): free units (none when blocked), sold and total units, the
 * price the pricing system calculated and the one it sends, the minimum stay and the demand label,
 * with the night's weekday, holiday and free units.
 *
 * Units come from the PMS inventory and the booked units (RoomNight); prices, minimum stay and
 * demand from the pricing calendar. Reference: ocupacao.calcular ("noites").
 */
final class OccupancyMap
{
    private function __construct()
    {
    }

    /**
     * @param list<RoomType>                            $rooms
     * @param array<string, list<InventoryNight>>       $inventory room code => nights
     * @param array<string, array<string, int>>|null    $booked    room code => night => units; null when not read
     * @param array<string, list<PriceNight>>           $pricing   room code => nights of the pricing calendar
     * @param array<string, string>                     $holidays  'YYYY-MM-DD' => holiday name
     * @param array<string, array<string, string>>      $demand    room code => night => demand label
     *
     * @return list<MapNight>
     */
    public static function of(DateTimeImmutable $today, int $nights, array $rooms, array $inventory, ?array $booked, array $pricing, array $holidays, array $demand = []): array
    {
        $byNight = [];
        foreach ($rooms as $room) {
            foreach ($inventory[$room->code] ?? [] as $night) {
                $byNight[$room->code][Days::key($night->date)] ??= $night;
            }
        }
        $prices = [];
        foreach ($pricing as $code => $list) {
            foreach ($list as $night) {
                $prices[$code][Days::key($night->date)] ??= $night;
            }
        }
        $out = [];
        for ($i = 0; $i < $nights; ++$i) {
            $night = Days::add($today, $i);
            $key = Days::key($night);
            $cells = [];
            $free = 0;
            foreach ($rooms as $room) {
                $inv = $byNight[$room->code][$key] ?? null;
                $cells[$room->code] = $inv === null
                    ? new MapCell()
                    : self::cell($room, $inv, $booked === null ? null : ($booked[$room->code][$key] ?? 0), $prices[$room->code][$key] ?? null, $demand[$room->code][$key] ?? null);
                $free += $cells[$room->code]->free ?? 0;
            }
            $out[] = new MapNight($night, Days::weekday($night), $holidays[$key] ?? null, $cells, $free);
        }

        return $out;
    }

    private static function cell(RoomType $room, InventoryNight $night, ?int $booked, ?PriceNight $price, ?string $demand): MapCell
    {
        $state = RoomNight::of($room->units, $night, $booked);

        return new MapCell($state->free(), $state->sold, $state->total, $price?->price, $price?->userPrice, $price?->minStay, $demand);
    }
}
