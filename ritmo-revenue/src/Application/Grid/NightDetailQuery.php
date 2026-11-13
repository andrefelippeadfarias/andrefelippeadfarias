<?php

declare(strict_types=1);

namespace Ritmo\Application\Grid;

use DateTimeImmutable;
use OutOfBoundsException;
use Ritmo\Domain\Model\BookingRecord;
use Ritmo\Domain\Support\Days;
use Ritmo\Domain\Write\Change\ChangeRecord;
use Ritmo\Infrastructure\Db\Repository\BookingRepository;
use Ritmo\Infrastructure\Db\Repository\ChangeLogRepository;
use Ritmo\Infrastructure\Db\Repository\DetectedFreezeRepository;
use Ritmo\Infrastructure\Db\Repository\PlanItemRepository;
use Ritmo\Infrastructure\Db\Repository\RoomTypeRepository;
use Ritmo\Infrastructure\Db\Repository\StepLedgerRepository;

/**
 * One room night opened from the grid: the cell plus the bookings on it (PMS id and channel
 * only), the freeze, and its history (the plugin's writes, the pace steps, the decisions of the
 * last runs). Read only.
 */
final class NightDetailQuery
{
    private const HISTORY = 50;

    private const DECISIONS = 5;

    private const CHANGE_ROWS = 1000;

    public function __construct(
        private readonly NightGridQuery $grid,
        private readonly RoomTypeRepository $roomTypes,
        private readonly BookingRepository $bookings,
        private readonly DetectedFreezeRepository $freezes,
        private readonly ChangeLogRepository $changes,
        private readonly StepLedgerRepository $steps,
        private readonly PlanItemRepository $planItems,
    ) {
    }

    /**
     * @throws OutOfBoundsException when the property or the room does not exist
     */
    public function detail(string $propertyId, string $roomCode, DateTimeImmutable $night): NightDetail
    {
        $night = Days::normalize($night);
        $cell = $this->grid->one($propertyId, $roomCode, $night);
        $room = $this->roomTypes->find($roomCode) ?? throw new OutOfBoundsException(sprintf('Unknown room "%s".', $roomCode));
        $bookings = array_values(array_filter(
            $this->bookings->overlapping($night, $night, $roomCode),
            static fn (BookingRecord $b): bool => $b->status->occupies(),
        ));
        $freeze = null;
        foreach ($this->freezes->unreleased($propertyId) as $candidate) {
            if ($candidate->roomCode === $roomCode && in_array(Days::key($night), array_map(Days::key(...), $candidate->nights), true)) {
                $freeze = $candidate;
            }
        }
        $changes = array_values(array_filter(
            array_reverse($this->changes->latest($propertyId, self::CHANGE_ROWS)),
            static fn (ChangeRecord $c): bool => $c->roomCode === $roomCode && $c->night !== null && Days::key($c->night) === Days::key($night),
        ));

        return new NightDetail(
            $cell,
            $room,
            array_map(static fn (BookingRecord $b): array => ['ref' => $b->pmsRef, 'channel' => $b->channel, 'units' => max(1, $b->units)], $bookings),
            $freeze,
            array_slice($changes, 0, self::HISTORY),
            array_slice($this->steps->forNight($roomCode, $night), 0, self::HISTORY),
            $this->decisions($roomCode, $night),
        );
    }

    /**
     * What the last runs decided for the night, newest first: run, status and reason.
     *
     * @return list<array{run_id: int, status: string, reason: string}>
     */
    private function decisions(string $roomCode, DateTimeImmutable $night): array
    {
        $groups = [];
        foreach ($this->planItems->forNight($roomCode, $night, self::DECISIONS * 3) as $item) {
            $groups[$item['run_id']][] = $item;
        }
        $out = [];
        foreach ($groups as $runId => $items) {
            $planned = (new PlanIndex($items))->night($roomCode, Days::key($night));
            $out[] = ['run_id' => (int) $runId, 'status' => (string) $planned->status, 'reason' => $planned->reason];
        }

        return array_slice($out, 0, self::DECISIONS);
    }
}
