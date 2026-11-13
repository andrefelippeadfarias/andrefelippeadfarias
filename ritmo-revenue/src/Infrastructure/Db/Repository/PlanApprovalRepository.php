<?php

declare(strict_types=1);

namespace Ritmo\Infrastructure\Db\Repository;

use DateTimeImmutable;
use Ritmo\Infrastructure\Db\Database;
use Ritmo\Infrastructure\Db\DbValue;

/**
 * Plans waiting for a person (table plan_approvals). A row is created pending and
 * leaves that state once: approved, rejected, expired or superseded. Every change of state is
 * conditional on the row still being pending, so two people deciding at once cannot both win.
 */
final class PlanApprovalRepository
{
    public const PENDING = 'pending';

    public const APPROVED = 'approved';

    private const TABLE = 'plan_approvals';

    public function __construct(private readonly Database $db)
    {
    }

    /**
     * @param list<array<string, mixed>> $entries the entries of the plan (key, kind, room, night, fields, group)
     */
    public function create(
        ?int $runId,
        string $propertyId,
        string $kind,
        string $planHash,
        DateTimeImmutable $planDay,
        ?int $rulesetVersion,
        DateTimeImmutable $calendarReadAt,
        DateTimeImmutable $expiresAt,
        array $entries,
        DateTimeImmutable $now,
    ): int {
        return $this->db->insert(self::TABLE, [
            'run_id' => $runId,
            'property_code' => $propertyId,
            'kind' => DbValue::word($kind, 16),
            'status' => self::PENDING,
            'plan_hash' => DbValue::word($planHash, 64),
            'plan_day' => DbValue::night($planDay),
            'ruleset_version' => $rulesetVersion,
            'calendar_read_at' => DbValue::instant($calendarReadAt),
            'expires_at' => DbValue::instant($expiresAt),
            'entries' => DbValue::json($entries),
            'created_at' => DbValue::instant($now),
        ]);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(int $id): ?array
    {
        $row = $this->db->selectRow(sprintf('SELECT * FROM %s WHERE id = ?', $this->db->table(self::TABLE)), [$id]);

        return $row === null ? null : self::decode($row);
    }

    /**
     * The pending rows of a property (of one kind, when given), oldest first.
     *
     * @return list<array<string, mixed>>
     */
    public function pending(string $propertyId, ?string $kind = null): array
    {
        return $this->withStatus($propertyId, self::PENDING, $kind);
    }

    /**
     * Requests approved by a person and not written yet, oldest first.
     *
     * @return list<array<string, mixed>>
     */
    public function approved(string $propertyId, ?string $kind = null): array
    {
        return $this->withStatus($propertyId, self::APPROVED, $kind);
    }

    /**
     * The latest requests of a property, newest first, whatever their status.
     *
     * @return list<array<string, mixed>>
     */
    public function latest(string $propertyId, int $limit = 20): array
    {
        return array_map([self::class, 'decode'], $this->db->select(
            sprintf('SELECT * FROM %s WHERE property_code = ? ORDER BY created_at DESC, id DESC LIMIT ?', $this->db->table(self::TABLE)),
            [$propertyId, max(1, min(200, $limit))]
        ));
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function withStatus(string $propertyId, string $status, ?string $kind): array
    {
        $sql = sprintf('SELECT * FROM %s WHERE property_code = ? AND status = ?', $this->db->table(self::TABLE));
        $params = [$propertyId, $status];
        if ($kind !== null) {
            $sql .= ' AND kind = ?';
            $params[] = $kind;
        }

        return array_map([self::class, 'decode'], $this->db->select($sql . ' ORDER BY created_at, id', $params));
    }

    /**
     * Records a decision on a pending row.
     *
     * @param list<string> $approvedKeys
     * @param list<string> $rejectedKeys
     *
     * @return bool false when the row was no longer pending
     */
    public function decide(int $id, string $status, array $approvedKeys, array $rejectedKeys, int $userId, string $capability, DateTimeImmutable $at, string $note = ''): bool
    {
        return $this->db->execute(
            sprintf('UPDATE %s SET status = ?, approved_keys = ?, rejected_keys = ?, decided_by = ?, decided_at = ?, capability = ?, note = ? WHERE id = ? AND status = ?', $this->db->table(self::TABLE)),
            [DbValue::word($status, 24), DbValue::json($approvedKeys), DbValue::json($rejectedKeys), $userId, DbValue::instant($at), DbValue::word($capability, 32), DbValue::clip($note, 500), $id, self::PENDING]
        ) === 1;
    }

    /**
     * Ends a pending row without a decision (expired, superseded) with the reason.
     *
     * @return bool false when the row was no longer pending
     */
    public function close(int $id, string $status, string $reason): bool
    {
        return $this->db->execute(
            sprintf('UPDATE %s SET status = ?, expiry_reason = ? WHERE id = ? AND status = ?', $this->db->table(self::TABLE)),
            [DbValue::word($status, 24), DbValue::word($reason, 64), $id, self::PENDING]
        ) === 1;
    }

    /**
     * Ends an approved row (the write ran, or it could not and the approval lapsed).
     */
    public function finish(int $id, string $fromStatus, string $status, string $reason, ?int $writeRunId): bool
    {
        return $this->db->execute(
            sprintf('UPDATE %s SET status = ?, expiry_reason = ?, write_run_id = ? WHERE id = ? AND status = ?', $this->db->table(self::TABLE)),
            [DbValue::word($status, 24), $reason === '' ? '' : DbValue::word($reason, 64), $writeRunId, $id, DbValue::word($fromStatus, 24)]
        ) === 1;
    }

    /**
     * @param array<string, string|null> $row
     *
     * @return array<string, mixed>
     */
    private static function decode(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'run_id' => DbValue::nullableInt($row['run_id']),
            'property_id' => (string) $row['property_code'],
            'kind' => (string) $row['kind'],
            'status' => (string) $row['status'],
            'plan_hash' => (string) $row['plan_hash'],
            'plan_day' => DbValue::requireNight($row['plan_day']),
            'ruleset_version' => DbValue::nullableInt($row['ruleset_version']),
            'calendar_read_at' => DbValue::requireInstant($row['calendar_read_at']),
            'expires_at' => DbValue::requireInstant($row['expires_at']),
            'expiry_reason' => (string) $row['expiry_reason'],
            'entries' => DbValue::toArray($row['entries']),
            'approved_keys' => DbValue::toArray($row['approved_keys']),
            'rejected_keys' => DbValue::toArray($row['rejected_keys']),
            'decided_by' => DbValue::nullableInt($row['decided_by']),
            'decided_at' => DbValue::toInstant($row['decided_at']),
            'capability' => (string) $row['capability'],
            'note' => (string) ($row['note'] ?? ''),
            'write_run_id' => DbValue::nullableInt($row['write_run_id']),
            'created_at' => DbValue::requireInstant($row['created_at']),
        ];
    }
}
