<?php

declare(strict_types=1);

namespace Ritmo\Infrastructure\Db\Repository;

use DateTimeImmutable;
use Ritmo\Domain\Pricing\Cadence\SoldBase;
use Ritmo\Domain\Pricing\Cadence\StepLedger;
use Ritmo\Domain\Pricing\Cadence\StepRecord;
use Ritmo\Domain\Pricing\StepDirection;
use Ritmo\Infrastructure\Db\Database;
use Ritmo\Infrastructure\Db\DbValue;

/**
 * The step ledger as the write path keeps it (DraftVerify columns of step_ledger): steps are
 * recorded only once their write is confirmed stored, marked delivered with the push that carried
 * them (when the PMS price agrees), and marked undone when an undo of the night is confirmed.
 * The cadence reads ledger(): undone steps are left out, delivered steps carry their push.
 */
final class WrittenStepRepository
{
    private const TABLE = 'step_ledger';

    public function __construct(private readonly Database $db)
    {
    }

    /**
     * @param string $writeRef the journal row of the write that carried the step
     */
    public function record(string $propertyId, StepRecord $step, ?int $runId = null, string $writeRef = ''): int
    {
        return $this->db->insert(self::TABLE, [
            'property_code' => $propertyId,
            'room_code' => $step->roomCode,
            'night' => DbValue::night($step->night),
            'direction' => $step->direction->value,
            'from_value' => $step->priceFrom,
            'to_value' => $step->priceTo,
            'pace_status' => $step->sourceStatus === '' ? '' : DbValue::word($step->sourceStatus, 16),
            'units_sold' => $step->sold,
            'units_total' => $step->soldTotal,
            'count_basis' => $step->soldBase->value,
            'reached_channel' => $step->onChannelAt !== null,
            'run_id' => $runId,
            'recorded_at' => DbValue::instant($step->writtenAt),
            'reached_channel_at' => DbValue::nullableInstant($step->onChannelAt),
            'write_ref' => DbValue::clip($writeRef, 64),
        ]);
    }

    /**
     * The steps of the property not yet on the channel and not undone, by row id.
     *
     * @return array<int, StepRecord>
     */
    public function pending(string $propertyId): array
    {
        $rows = $this->db->select(
            sprintf('SELECT * FROM %s WHERE property_code = ? AND reached_channel = ? AND undone_at IS NULL ORDER BY recorded_at, id', $this->db->table(self::TABLE)),
            [$propertyId, false]
        );
        $steps = [];
        foreach ($rows as $row) {
            $steps[(int) $row['id']] = self::step($row);
        }

        return $steps;
    }

    /**
     * @param array<int, DateTimeImmutable> $delivered row id => the push that carried the step
     *
     * @return int rows marked
     */
    public function markDelivered(array $delivered): int
    {
        $marked = 0;
        foreach ($delivered as $id => $push) {
            $marked += $this->db->execute(
                sprintf('UPDATE %s SET reached_channel = ?, reached_channel_at = ? WHERE id = ? AND reached_channel = ?', $this->db->table(self::TABLE)),
                [true, DbValue::instant($push), (int) $id, false]
            );
        }

        return $marked;
    }

    /**
     * Marks undone the steps a run wrote on a room night (an undo of that change was confirmed).
     */
    public function markUndone(int $runId, string $roomCode, DateTimeImmutable $night, DateTimeImmutable $at): int
    {
        return $this->db->execute(
            sprintf('UPDATE %s SET undone_at = ? WHERE run_id = ? AND room_code = ? AND night = ? AND undone_at IS NULL', $this->db->table(self::TABLE)),
            [DbValue::instant($at), $runId, $roomCode, DbValue::night($night)]
        );
    }

    /**
     * The steps of a property's nights between two dates, as the cadence reads them, without the
     * undone ones (an undone step measured nothing).
     */
    public function ledger(string $propertyId, DateTimeImmutable $from, DateTimeImmutable $to): StepLedger
    {
        $rows = $this->db->select(
            sprintf('SELECT * FROM %s WHERE property_code = ? AND night >= ? AND night <= ? AND undone_at IS NULL ORDER BY recorded_at, id', $this->db->table(self::TABLE)),
            [$propertyId, DbValue::night($from), DbValue::night($to)]
        );

        return new StepLedger(array_map([self::class, 'step'], $rows));
    }

    /**
     * @param array<string, string|null> $row
     */
    private static function step(array $row): StepRecord
    {
        $recorded = DbValue::requireInstant($row['recorded_at']);
        $reached = (bool) (int) $row['reached_channel'];

        return new StepRecord(
            (string) $row['room_code'],
            DbValue::requireNight($row['night']),
            (float) $row['from_value'],
            (float) $row['to_value'],
            StepDirection::from((string) $row['direction']),
            (string) $row['pace_status'],
            DbValue::nullableInt($row['units_sold']),
            SoldBase::tryFrom((string) $row['count_basis']) ?? SoldBase::Room,
            DbValue::nullableInt($row['units_total']),
            $recorded,
            false,
            $reached ? (DbValue::toInstant($row['reached_channel_at']) ?? $recorded) : null,
        );
    }
}
