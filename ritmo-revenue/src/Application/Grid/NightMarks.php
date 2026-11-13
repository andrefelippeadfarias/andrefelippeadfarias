<?php

declare(strict_types=1);

namespace Ritmo\Application\Grid;

use DateTimeImmutable;
use Ritmo\Domain\Model\BookingRecord;
use Ritmo\Domain\Orders\OrderBook;
use Ritmo\Domain\Support\Days;
use Ritmo\Domain\Write\Change\ChangeRecord;
use Ritmo\Domain\Write\ThirdParty\DetectedFreeze;

/**
 * The per-night facts of a range read in bulk (one statement per source) and indexed by
 * "room|YYYY-MM-DD": units sold from the stored bookings, freezes, owner orders, open alerts and
 * the newest write of the plugin.
 */
final class NightMarks
{
    /** @var array<string, int> */
    private array $sold = [];

    /** @var array<string, DetectedFreeze> */
    private array $freezes = [];

    /** @var array<string, int> */
    private array $alerts = [];

    /** @var array<string, DateTimeImmutable> */
    private array $written = [];

    /**
     * @param list<BookingRecord>                                                $bookings overlapping the range
     * @param list<DetectedFreeze>                                               $freezes  not released
     * @param list<array{room_code: string, night: DateTimeImmutable|null}>      $alerts   unresolved
     * @param list<ChangeRecord>                                                 $changes  recent rows, any order
     */
    public function __construct(array $bookings, array $freezes, private readonly OrderBook $orders, array $alerts, array $changes, DateTimeImmutable $now)
    {
        foreach ($bookings as $booking) {
            if ($booking->roomCode === null || !$booking->status->occupies()) {
                continue;
            }
            foreach ($booking->nights() as $night) {
                $key = PlanIndex::key($booking->roomCode, Days::key($night));
                $this->sold[$key] = ($this->sold[$key] ?? 0) + max(1, $booking->units);
            }
        }
        foreach ($freezes as $freeze) {
            if ($freeze->frozenUntil !== null && $freeze->frozenUntil <= $now) {
                continue;
            }
            foreach ($freeze->nights as $night) {
                $this->freezes[PlanIndex::key($freeze->roomCode, Days::key($night))] = $freeze;
            }
        }
        foreach ($alerts as $alert) {
            if ($alert['room_code'] !== '' && $alert['night'] !== null) {
                $key = PlanIndex::key($alert['room_code'], Days::key($alert['night']));
                $this->alerts[$key] = ($this->alerts[$key] ?? 0) + 1;
            }
        }
        foreach ($changes as $change) {
            if ($change->night === null) {
                continue;
            }
            $key = PlanIndex::key($change->roomCode, Days::key($change->night));
            if (!isset($this->written[$key]) || $change->createdAt > $this->written[$key]) {
                $this->written[$key] = $change->createdAt;
            }
        }
    }

    /** Units sold by the stored bookings that occupy the night. */
    public function sold(string $room, string $night): int
    {
        return $this->sold[PlanIndex::key($room, $night)] ?? 0;
    }

    public function freeze(string $room, string $night): ?DetectedFreeze
    {
        return $this->freezes[PlanIndex::key($room, $night)] ?? null;
    }

    /**
     * @return list<string> ids of the owner orders that cover the night
     */
    public function orders(string $room, DateTimeImmutable $night): array
    {
        $ids = [];
        foreach ($this->orders->all() as $order) {
            if ($order->covers($room, $night)) {
                $ids[] = $order->id;
            }
        }

        return $ids;
    }

    public function orderBook(): OrderBook
    {
        return $this->orders;
    }

    public function alerts(string $room, string $night): int
    {
        return $this->alerts[PlanIndex::key($room, $night)] ?? 0;
    }

    public function lastWritten(string $room, string $night): ?DateTimeImmutable
    {
        return $this->written[PlanIndex::key($room, $night)] ?? null;
    }
}
