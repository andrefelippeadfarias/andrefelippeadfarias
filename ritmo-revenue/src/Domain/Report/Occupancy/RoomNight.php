<?php

declare(strict_types=1);

namespace Ritmo\Domain\Report\Occupancy;

use Ritmo\Domain\Model\InventoryNight;

/**
 * One room night as occupancy counts it: units sold, the units it counts as capacity, the units
 * still free and whether it is blocked (closed in the PMS with units unsold).
 *
 * With the bookings read (RP-1), sold = min(units, booked) and a night is blocked when the PMS
 * reports nothing available while fewer units than the room's are booked; a blocked single-unit
 * night is left out of both counts (it was never for sale). Without the bookings, sold = units -
 * available (M1): a night closed without bookings counts as sold.
 *
 * Reference: pacing.metricas.ler_calendario and ocupacao (divergence RP-X1: blocked from the PMS
 * inventory and the booked units, not from the pricing calendar's unavailable flags).
 */
final class RoomNight
{
    private function __construct(
        public readonly int $sold,
        public readonly int $total,
        public readonly bool $blocked,
    ) {
    }

    /**
     * @param int|null $booked units booked that night; null when the bookings were not read
     */
    public static function of(int $units, InventoryNight $night, ?int $booked): self
    {
        if ($booked === null) {
            return new self(max(0, min($units, $units - $night->available)), $units, false);
        }
        $sold = max(0, min($units, $booked));

        return new self($sold, $units, $night->available <= 0 && $sold < $units);
    }

    /**
     * False for a blocked single-unit night: it counts neither as sold nor as capacity.
     */
    public function counts(): bool
    {
        return !($this->blocked && $this->total === 1);
    }

    /**
     * Units still for sale (none on a blocked night).
     */
    public function free(): int
    {
        return $this->blocked ? 0 : $this->total - $this->sold;
    }
}
