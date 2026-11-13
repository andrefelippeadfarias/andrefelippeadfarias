<?php

declare(strict_types=1);

namespace Ritmo\Infrastructure\Db\Repository;

use DateTimeImmutable;
use Ritmo\Infrastructure\Db\Database;
use Ritmo\Infrastructure\Db\DbValue;

/**
 * The write-ahead journal of the write path (table write_ops): one row per step, inserted before
 * the first call of the run and marked "sending" before its own call, so a process that dies
 * mid-call leaves a row that says the outcome is unknown. Rows never hold credentials or headers:
 * the payload is the request body only.
 */
final class WriteOpRepository
{
    private const TABLE = 'write_ops';

    /** Statuses whose outcome must be read back by a later run before anything is sent again. */
    public const UNSETTLED = ['planned', 'sending', 'accepted', 'unknown'];

    private const ERROR_LENGTH = 300;

    public function __construct(private readonly Database $db)
    {
    }

    /**
     * @param array{run_id: int|null, property_code: string, provider: string, operation: string, target_ref: string,
     *              group_key: string, slice_number: int, label: string, dates: list<string>, payload: array<array-key, mixed>,
     *              before_state: array<array-key, mixed>, intended_state: array<array-key, mixed>, token_id: string,
     *              plan_hash: string, body_hash: string} $row
     *
     * @return int id of the row
     */
    public function insertPlanned(array $row, DateTimeImmutable $at): int
    {
        return $this->db->insert(self::TABLE, [
            'run_id' => $row['run_id'],
            'property_code' => DbValue::word($row['property_code'], 32),
            'provider' => DbValue::word($row['provider'], 32),
            'operation' => DbValue::word($row['operation'], 32),
            'target_ref' => DbValue::reference($row['target_ref'], 64),
            'group_key' => DbValue::reference($row['group_key'], 64),
            'slice_number' => $row['slice_number'],
            'label' => DbValue::clip($row['label'], 32),
            'dates' => DbValue::json($row['dates']),
            'payload' => DbValue::json($row['payload']),
            'before_state' => DbValue::json($row['before_state']),
            'intended_state' => DbValue::json($row['intended_state']),
            'status' => 'planned',
            'token_id' => DbValue::reference($row['token_id'], 64),
            'plan_hash' => DbValue::reference($row['plan_hash'], 64),
            'body_hash' => DbValue::reference($row['body_hash'], 64),
            'created_at' => DbValue::instant($at),
        ]);
    }

    /**
     * Marks the row as being sent; committed before the HTTP call.
     */
    public function markSending(int $id, DateTimeImmutable $at): void
    {
        $this->db->execute(
            sprintf('UPDATE %s SET status = ?, attempts = attempts + 1, started_at = ? WHERE id = ?', $this->db->table(self::TABLE)),
            ['sending', DbValue::instant($at), $id]
        );
    }

    public function finish(int $id, string $status, ?int $httpStatus, ?string $error, DateTimeImmutable $at): void
    {
        $this->db->update(self::TABLE, [
            'status' => DbValue::word($status, 16),
            'http_status' => $httpStatus,
            'error' => $error === null ? null : self::line($error),
            'finished_at' => DbValue::instant($at),
        ], ['id' => $id]);
    }

    /**
     * @param array<array-key, mixed> $observed the state read back
     */
    public function observe(int $id, string $status, array $observed): void
    {
        $this->db->update(self::TABLE, ['status' => DbValue::word($status, 16), 'observed_state' => DbValue::json($observed)], ['id' => $id]);
    }

    /**
     * Records that a later run classified these rows.
     *
     * @param list<int> $ids
     */
    public function resolve(array $ids, int $runId, string $status): void
    {
        foreach ($ids as $id) {
            $this->db->update(self::TABLE, ['resolved_run_id' => $runId, 'status' => DbValue::word($status, 16)], ['id' => $id]);
        }
    }

    /**
     * @return list<array<string, mixed>> the rows of a run in schedule order
     */
    public function ofRun(int $runId): array
    {
        return array_map(self::row(...), $this->db->select(
            sprintf('SELECT * FROM %s WHERE run_id = ? ORDER BY slice_number, id', $this->db->table(self::TABLE)),
            [$runId]
        ));
    }

    /**
     * Rows of earlier runs of the property that a later run has not classified yet: those whose
     * outcome nobody read back, and every row of a deletion-and-rewrite group (a group may have
     * stopped between its two halves).
     *
     * @return list<array<string, mixed>> oldest first
     */
    public function unsettled(string $propertyCode, int $exceptRunId): array
    {
        $marks = implode(', ', array_fill(0, count(self::UNSETTLED), '?'));

        return array_map(self::row(...), $this->db->select(
            sprintf(
                "SELECT * FROM %s WHERE property_code = ? AND resolved_run_id IS NULL AND (run_id IS NULL OR run_id <> ?) AND (status IN (%s) OR group_key <> '') ORDER BY id",
                $this->db->table(self::TABLE),
                $marks
            ),
            [$propertyCode, $exceptRunId, ...self::UNSETTLED]
        ));
    }

    /**
     * How many runs in a row, the latest first, the same body was refused (400) for the property.
     */
    public function refusalStreak(string $propertyCode, string $bodyHash): int
    {
        $rows = $this->db->select(
            sprintf('SELECT run_id, status FROM %s WHERE property_code = ? AND body_hash = ? AND status NOT IN (?, ?) ORDER BY id DESC', $this->db->table(self::TABLE)),
            [$propertyCode, $bodyHash, 'planned', 'abandoned']
        );
        $streak = 0;
        $runs = [];
        foreach ($rows as $row) {
            if ($row['status'] !== 'rejected' && $row['status'] !== 'quarantined') {
                break;
            }
            $runs[(string) $row['run_id']] = true;
            $streak = count($runs);
        }

        return $streak;
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    private static function row(array $row): array
    {
        foreach (['dates', 'payload', 'before_state', 'intended_state', 'observed_state'] as $json) {
            $row[$json] = DbValue::toArray($row[$json] ?? null);
        }
        foreach (['id', 'slice_number', 'attempts'] as $int) {
            $row[$int] = (int) $row[$int];
        }
        $row['run_id'] = DbValue::nullableInt($row['run_id'] === null ? null : (string) $row['run_id']);
        $row['http_status'] = DbValue::nullableInt($row['http_status'] === null ? null : (string) $row['http_status']);

        return $row;
    }

    private static function line(string $error): string
    {
        return mb_substr(trim((string) preg_replace('/\s+/u', ' ', $error)), 0, self::ERROR_LENGTH, 'UTF-8');
    }
}
