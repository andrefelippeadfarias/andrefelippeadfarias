<?php

declare(strict_types=1);

namespace Ritmo\Infrastructure\Schedule;

use DateTimeImmutable;
use Ritmo\Domain\Operations\Slot;
use Ritmo\Domain\Operations\SlotKind;
use Ritmo\Domain\Operations\SlotRecord;
use Ritmo\Domain\Operations\SlotStatus;
use Ritmo\Domain\Support\Clock;
use Ritmo\Infrastructure\Db\Database;
use Ritmo\Infrastructure\Db\DbValue;

/**
 * The slot occurrences of the properties (table scheduled_runs): one row per property, local
 * date-time and kind, created once however many ticks see it.
 */
final class ScheduledRunRepository
{
    private const TABLE = 'scheduled_runs';

    public function __construct(private readonly Database $db, private readonly Clock $clock)
    {
    }

    /**
     * Records a slot as due unless the same occurrence exists.
     *
     * @return bool true when the row was created
     */
    public function materialise(string $propertyId, Slot $slot): bool
    {
        return $this->db->insertIgnore(self::TABLE, [
            'property_code' => $propertyId,
            'slot_kind' => $slot->kind->value,
            'slot_local' => $slot->localKey(),
            'slot_at' => DbValue::instant($slot->at),
            'status' => SlotStatus::Due->value,
            'created_at' => DbValue::instant($this->clock->now()),
        ]);
    }

    /**
     * The due slots of a property up to $now, oldest first.
     *
     * @return list<SlotRecord>
     */
    public function due(string $propertyId, DateTimeImmutable $now): array
    {
        return array_map($this->hydrate(...), $this->db->select(
            sprintf('SELECT * FROM %s WHERE property_code = ? AND status = ? AND slot_at <= ? ORDER BY slot_at, id', $this->db->table(self::TABLE)),
            [$propertyId, SlotStatus::Due->value, DbValue::instant($now)]
        ));
    }

    /**
     * The instant of the oldest due slot of a property up to $now, or of any property with null.
     */
    public function oldestDueAt(?string $propertyId, DateTimeImmutable $now): ?DateTimeImmutable
    {
        $sql = sprintf('SELECT MIN(slot_at) FROM %s WHERE status = ? AND slot_at <= ?', $this->db->table(self::TABLE));
        $params = [SlotStatus::Due->value, DbValue::instant($now)];
        if ($propertyId !== null) {
            $sql .= ' AND property_code = ?';
            $params[] = $propertyId;
        }

        return DbValue::toInstant($this->db->selectValue($sql, $params));
    }

    /**
     * The slots of a property whose run is still marked as running.
     *
     * @return list<SlotRecord>
     */
    public function running(string $propertyId): array
    {
        return array_map($this->hydrate(...), $this->db->select(
            sprintf('SELECT * FROM %s WHERE property_code = ? AND status = ? ORDER BY slot_at, id', $this->db->table(self::TABLE)),
            [$propertyId, SlotStatus::Running->value]
        ));
    }

    public function find(int $id): ?SlotRecord
    {
        $row = $this->db->selectRow(sprintf('SELECT * FROM %s WHERE id = ?', $this->db->table(self::TABLE)), [$id]);

        return $row === null ? null : $this->hydrate($row);
    }

    public function findByKey(string $propertyId, Slot $slot): ?SlotRecord
    {
        $row = $this->db->selectRow(
            sprintf('SELECT * FROM %s WHERE property_code = ? AND slot_local = ? AND slot_kind = ?', $this->db->table(self::TABLE)),
            [$propertyId, $slot->localKey(), $slot->kind->value]
        );

        return $row === null ? null : $this->hydrate($row);
    }

    /**
     * The slot that started a run.
     */
    public function forRun(int $runId): ?SlotRecord
    {
        $row = $this->db->selectRow(sprintf('SELECT * FROM %s WHERE run_id = ? ORDER BY id DESC LIMIT 1', $this->db->table(self::TABLE)), [$runId]);

        return $row === null ? null : $this->hydrate($row);
    }

    /**
     * Records the start of the run of a slot; a run that already ended is closed with close().
     */
    public function started(int $id, ?int $runId, DateTimeImmutable $startedAt, int $delaySeconds, bool $promoted, SlotStatus $status): void
    {
        $this->db->update(self::TABLE, [
            'status' => $status->value,
            'run_id' => $runId,
            'started_at' => DbValue::instant($startedAt),
            'delay_seconds' => $delaySeconds,
            'promoted' => $promoted ? 1 : 0,
        ], ['id' => $id]);
    }

    /**
     * Closes a slot with a final status (a skipped slot, or the end of its run).
     */
    public function close(int $id, SlotStatus $status, DateTimeImmutable $at, string $note = ''): void
    {
        $this->db->update(self::TABLE, [
            'status' => $status->value,
            'finished_at' => DbValue::instant($at),
            'note' => DbValue::clip($note, 190),
        ], ['id' => $id]);
    }

    /**
     * The most recent slots of a property, newest first.
     *
     * @return list<SlotRecord>
     */
    public function latest(string $propertyId, int $limit = 50): array
    {
        return array_map($this->hydrate(...), $this->db->select(
            sprintf('SELECT * FROM %s WHERE property_code = ? ORDER BY slot_at DESC, id DESC LIMIT ?', $this->db->table(self::TABLE)),
            [$propertyId, max(1, $limit)]
        ));
    }

    public function purgeBefore(DateTimeImmutable $instant): int
    {
        return $this->db->execute(sprintf('DELETE FROM %s WHERE slot_at < ? AND status NOT IN (?, ?)', $this->db->table(self::TABLE)), [
            DbValue::instant($instant), SlotStatus::Due->value, SlotStatus::Running->value,
        ]);
    }

    /**
     * @param array<string, string|null> $row
     */
    private function hydrate(array $row): SlotRecord
    {
        [$date, $time] = Slot::parseLocalKey((string) $row['slot_local']);

        return new SlotRecord(
            (int) $row['id'],
            (string) $row['property_code'],
            new Slot(SlotKind::from((string) $row['slot_kind']), $date, $time, DbValue::requireInstant($row['slot_at'])),
            SlotStatus::from((string) $row['status']),
            (bool) (int) $row['promoted'],
            DbValue::nullableInt($row['run_id']),
            DbValue::toInstant($row['started_at']),
            DbValue::toInstant($row['finished_at']),
            DbValue::nullableInt($row['delay_seconds']),
            (string) $row['note'],
        );
    }
}
