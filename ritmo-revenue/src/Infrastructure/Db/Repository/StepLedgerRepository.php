<?php

declare(strict_types=1);

namespace Ritmo\Infrastructure\Db\Repository;

use DateTimeImmutable;
use InvalidArgumentException;
use Ritmo\Infrastructure\Db\Database;
use Ritmo\Infrastructure\Db\DbValue;

/**
 * The ledger of pace steps: every time a night's price was moved one step up or down. The
 * cadence rule (one step per night per day) reads the last step of a night from here.
 */
final class StepLedgerRepository
{
    public const UP = 'up';
    public const DOWN = 'down';

    private const TABLE = 'step_ledger';

    public function __construct(private readonly Database $db)
    {
    }

    /**
     * @param string   $direction  self::UP or self::DOWN
     * @param float    $from       value before the step
     * @param float    $to         value after the step
     * @param string   $paceStatus pace status that caused the step ("behind", "ahead", ...); empty when it was another rule
     * @param int|null $unitsSold  units sold on the night when the step was taken
     * @param int|null $unitsTotal units the count is out of
     * @param string   $countBasis what the two counts refer to ("room" or "property")
     *
     * @return int id of the entry
     *
     * @throws InvalidArgumentException on an unknown direction
     */
    public function record(
        string $propertyId,
        string $roomCode,
        DateTimeImmutable $night,
        string $direction,
        float $from,
        float $to,
        DateTimeImmutable $recordedAt,
        string $paceStatus = '',
        ?int $unitsSold = null,
        ?int $unitsTotal = null,
        string $countBasis = 'room',
        ?int $runId = null,
    ): int {
        if (!in_array($direction, [self::UP, self::DOWN], true)) {
            throw new InvalidArgumentException(sprintf('Unknown step direction "%s".', $direction));
        }

        return $this->db->insert(self::TABLE, [
            'property_code' => $propertyId,
            'room_code' => $roomCode,
            'night' => DbValue::night($night),
            'direction' => $direction,
            'from_value' => $from,
            'to_value' => $to,
            'pace_status' => $paceStatus === '' ? '' : DbValue::word($paceStatus, 16),
            'units_sold' => $unitsSold,
            'units_total' => $unitsTotal,
            'count_basis' => DbValue::word($countBasis, 16),
            'reached_channel' => false,
            'run_id' => $runId,
            'recorded_at' => DbValue::instant($recordedAt),
        ]);
    }

    /**
     * The most recent step of a room night; null when it never had one.
     *
     * @return array{id: int, property_id: string, room_code: string, night: DateTimeImmutable, direction: string, from: float, to: float, pace_status: string, units_sold: int|null, units_total: int|null, count_basis: string, reached_channel: bool, run_id: int|null, recorded_at: DateTimeImmutable}|null
     */
    public function lastStep(string $roomCode, DateTimeImmutable $night): ?array
    {
        return $this->forNight($roomCode, $night)[0] ?? null;
    }

    /**
     * Every step of a room night, newest first.
     *
     * @return list<array{id: int, property_id: string, room_code: string, night: DateTimeImmutable, direction: string, from: float, to: float, pace_status: string, units_sold: int|null, units_total: int|null, count_basis: string, reached_channel: bool, run_id: int|null, recorded_at: DateTimeImmutable}>
     */
    public function forNight(string $roomCode, DateTimeImmutable $night): array
    {
        $rows = $this->db->select(
            sprintf('SELECT * FROM %s WHERE room_code = ? AND night = ? ORDER BY recorded_at DESC, id DESC', $this->db->table(self::TABLE)),
            [$roomCode, DbValue::night($night)]
        );

        return array_map(static fn (array $row): array => [
            'id' => (int) $row['id'],
            'property_id' => (string) $row['property_code'],
            'room_code' => (string) $row['room_code'],
            'night' => DbValue::requireNight($row['night']),
            'direction' => (string) $row['direction'],
            'from' => (float) $row['from_value'],
            'to' => (float) $row['to_value'],
            'pace_status' => (string) $row['pace_status'],
            'units_sold' => DbValue::nullableInt($row['units_sold']),
            'units_total' => DbValue::nullableInt($row['units_total']),
            'count_basis' => (string) $row['count_basis'],
            'reached_channel' => (bool) (int) $row['reached_channel'],
            'run_id' => DbValue::nullableInt($row['run_id']),
            'recorded_at' => DbValue::requireInstant($row['recorded_at']),
        ], $rows);
    }

    /**
     * Marks as "reached the channel" the steps of a room recorded up to the instant its
     * prices were last pushed.
     *
     * @return int steps marked
     */
    public function markReachedChannel(string $roomCode, DateTimeImmutable $pushedAt): int
    {
        return $this->db->execute(
            sprintf('UPDATE %s SET reached_channel = ? WHERE room_code = ? AND reached_channel = ? AND recorded_at <= ?', $this->db->table(self::TABLE)),
            [true, $roomCode, false, DbValue::instant($pushedAt)]
        );
    }
}
