<?php

declare(strict_types=1);

namespace Ritmo\Application\Grid;

use DateTimeImmutable;

/**
 * One room night as the Nights screen shows it: occupancy from the PMS calendar and the stored
 * bookings, the price as the last run read it, what that run proposed and why, and the marks
 * (freeze, owner order, alert, last write). Plain values only: the screen formats them.
 */
final class NightCell
{
    /**
     * @param list<array{code: string, params: array<string, mixed>}> $trace    the rule trace of the last run's decision
     * @param list<string>                                           $orderIds owner orders covering the night
     */
    public function __construct(
        public readonly string $roomCode,
        public readonly DateTimeImmutable $night,
        public readonly string $nightType,
        public readonly string $period,
        public readonly int $daysOut,
        public readonly ?int $unitsSold,
        public readonly int $unitsTotal,
        public readonly bool $closed,
        public readonly ?float $priceNow,
        public readonly ?float $pmsPrice,
        public readonly PlannedNight $planned,
        public readonly array $trace,
        public readonly bool $frozen,
        public readonly ?DateTimeImmutable $frozenUntil,
        public readonly array $orderIds,
        public readonly int $alertCount,
        public readonly ?DateTimeImmutable $lastWritten,
        public readonly ?int $minStay,
        public readonly string $restriction,
    ) {
    }

    public function soldOut(): bool
    {
        return $this->unitsSold !== null && $this->unitsTotal > 0 && $this->unitsSold >= $this->unitsTotal;
    }

    /** Closed in the PMS with no booking on it. */
    public function closedWithoutBooking(): bool
    {
        return $this->closed && ($this->unitsSold ?? 0) === 0;
    }

    /** The night lies beyond what the last run priced. */
    public function priced(): bool
    {
        return $this->planned->status !== null;
    }
}
