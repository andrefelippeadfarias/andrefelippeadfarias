<?php

declare(strict_types=1);

namespace Ritmo\Domain\Alerts;

use Ritmo\Domain\Model\InventoryNight;
use Ritmo\Domain\Model\Restriction;
use Ritmo\Domain\Plan\PlanItem;
use Ritmo\Domain\Support\Days;

/**
 * The room nights closed in the PMS without a booking: the units off sale exceed the units
 * booked, max(0, units - max(available, 0)) > booked, or the night is under a blackout. The plan
 * never prices them (RunInputs::$blocked) and each raises inventory_closed_without_booking.
 *
 * Reference: SKILL.md (closed nights are blocks, "--bloqueios"), L 01/10 ("Indisponível pode ser
 * bloqueio").
 */
final class ClosedWithoutBooking
{
    /**
     * @param array<string, iterable<InventoryNight>>   $inventory room code => nights of the PMS calendar
     * @param array<string, array<string, int>>         $booked    room code => 'YYYY-MM-DD' => units booked (BookedUnits::byRoomNight)
     * @param array<string, int>                        $units     room code => units of the room type
     *
     * @return list<ClosedNight> by room, then night
     */
    public static function find(array $inventory, array $booked, array $units): array
    {
        $found = [];
        ksort($inventory);
        foreach ($inventory as $room => $nights) {
            $room = (string) $room;
            foreach ($nights as $night) {
                $sold = $booked[$room][Days::key($night->date)] ?? 0;
                $offSale = max(0, ($units[$room] ?? 0) - max($night->available, 0));
                $blackout = $night->restriction === Restriction::Blackout;
                if ($offSale > $sold || $blackout) {
                    $found[] = new ClosedNight($room, $night->date, $offSale, $sold, $blackout);
                }
            }
        }
        usort($found, static fn (ClosedNight $a, ClosedNight $b): int => [$a->roomCode, $a->night] <=> [$b->roomCode, $b->night]);

        return $found;
    }

    /**
     * The nights as the plan's blocked set.
     *
     * @param list<ClosedNight> $nights
     *
     * @return array<string, true> PlanItem keys
     */
    public static function blocked(array $nights): array
    {
        $keys = [];
        foreach ($nights as $night) {
            $keys[PlanItem::keyOf($night->roomCode, $night->night)] = true;
        }

        return $keys;
    }
}
