<?php

declare(strict_types=1);

namespace Ritmo\Infrastructure\Db\Repository;

use DateTimeImmutable;
use InvalidArgumentException;
use Ritmo\Domain\Write\Change\ChangeRecord;
use Ritmo\Domain\Write\Change\UndoAction;
use Ritmo\Domain\Write\State\Provider;
use Ritmo\Infrastructure\Db\Database;
use Ritmo\Infrastructure\Db\DbValue;

/**
 * The change log: one row per field the plugin changed and confirmed, with before, after, why,
 * who and how to undo the night (columns of migration 8). Rows are never deleted; an undo marks
 * them undone and adds its own rows pointing back ("undo_of").
 */
final class ChangeLogRepository
{
    private const TABLE = 'change_log';

    public function __construct(private readonly Database $db)
    {
    }

    /**
     * @param list<ChangeRecord> $records
     *
     * @return list<int> the ids, in order
     */
    public function recordAll(array $records): array
    {
        return $this->db->transaction(fn (): array => array_map(fn (ChangeRecord $r): int => $this->record($r), $records));
    }

    public function record(ChangeRecord $record): int
    {
        return $this->db->insert(self::TABLE, [
            'run_id' => $record->runId,
            'property_code' => $record->propertyId,
            'room_code' => $record->roomCode,
            'night' => DbValue::nullableNight($record->night),
            'provider' => $record->provider->value,
            'field' => DbValue::word($record->field, 32),
            'old_value' => DbValue::json(['v' => $record->oldValue]),
            'new_value' => DbValue::json(['v' => $record->newValue]),
            'reason' => DbValue::json(['code' => $record->reasonCode, 'text' => DbValue::clip($record->reasonText, 255)]),
            'undo_plan' => DbValue::json($record->undo->toArray()),
            'actor' => $record->actor,
            'created_at' => DbValue::instant($record->createdAt),
            'undone_at' => DbValue::nullableInstant($record->undoneAt),
            'group_key' => DbValue::clip($record->groupKey, 64),
            'undo_of' => $record->undoOf,
            'confirm_level' => DbValue::word($record->confirmLevel, 16),
            'order_id' => $record->orderId ?? '',
            'old_amount' => $record->oldAmount,
            'new_amount' => $record->newAmount,
        ]);
    }

    /**
     * @param list<int> $ids
     *
     * @return list<ChangeRecord> in the order they were recorded
     */
    public function byIds(array $ids): array
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        if ($ids === []) {
            return [];
        }

        return $this->rows(sprintf('id IN (%s)', implode(', ', array_fill(0, count($ids), '?'))), $ids);
    }

    /**
     * @return list<ChangeRecord>
     */
    public function forRun(int $runId): array
    {
        return $this->rows('run_id = ?', [$runId]);
    }

    /**
     * The latest rows of a property, newest first.
     *
     * @return list<ChangeRecord>
     */
    public function latest(string $propertyId, int $limit = 200): array
    {
        if ($limit < 1 || $limit > 1000) {
            throw new InvalidArgumentException('Read between 1 and 1000 rows.');
        }

        return array_reverse($this->rows(sprintf('property_code = ? ORDER BY created_at DESC, id DESC LIMIT %d', $limit), [$propertyId], false));
    }

    /**
     * Marks the rows as undone by a confirmed undo.
     *
     * @param list<int> $ids
     *
     * @return int rows marked
     */
    public function markUndone(array $ids, DateTimeImmutable $at): int
    {
        $marked = 0;
        foreach (array_unique($ids) as $id) {
            $marked += $this->db->execute(
                sprintf('UPDATE %s SET undone_at = ? WHERE id = ? AND undone_at IS NULL', $this->db->table(self::TABLE)),
                [DbValue::instant($at), (int) $id]
            );
        }

        return $marked;
    }

    /**
     * Only the changes of nights and listings: the operation events kept in the same table
     * (OperationEventRepository, no provider) are left out.
     *
     * @param list<int|string> $params
     *
     * @return list<ChangeRecord>
     */
    private function rows(string $where, array $params, bool $ordered = true): array
    {
        $rows = $this->db->select(
            sprintf("SELECT * FROM %s WHERE provider <> '' AND %s%s", $this->db->table(self::TABLE), $where, $ordered ? ' ORDER BY created_at, id' : ''),
            $params
        );

        return array_map([self::class, 'fromRow'], $rows);
    }

    /**
     * @param array<string, string|null> $row
     */
    private static function fromRow(array $row): ChangeRecord
    {
        $reason = DbValue::toArray($row['reason']);
        $order = (string) $row['order_id'];

        return new ChangeRecord(
            (string) $row['property_code'],
            Provider::from((string) $row['provider']),
            (string) $row['room_code'],
            DbValue::toNight($row['night']),
            (string) $row['field'],
            self::value($row['old_value']),
            self::value($row['new_value']),
            UndoAction::fromArray(DbValue::toArray($row['undo_plan'])),
            (string) $row['actor'],
            DbValue::requireInstant($row['created_at']),
            DbValue::nullableFloat($row['old_amount']),
            DbValue::nullableFloat($row['new_amount']),
            (string) ($reason['code'] ?? ''),
            (string) ($reason['text'] ?? ''),
            $order === '' ? null : $order,
            DbValue::nullableInt($row['run_id']),
            (string) $row['group_key'],
            DbValue::nullableInt($row['undo_of']),
            (string) $row['confirm_level'],
            (int) $row['id'],
            DbValue::toInstant($row['undone_at']),
        );
    }

    private static function value(?string $stored): int|float|string|null
    {
        $value = DbValue::toArray($stored)['v'] ?? null;

        return is_int($value) || is_float($value) || is_string($value) ? $value : null;
    }
}
