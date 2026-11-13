<?php

declare(strict_types=1);

namespace Ritmo\Infrastructure\Schedule;

use DateTimeImmutable;
use Ritmo\Domain\Alerts\AlertDraft;
use Ritmo\Domain\Alerts\AlertRecord;
use Ritmo\Domain\Alerts\AlertSeverity;
use Ritmo\Domain\Alerts\AlertStatus;
use Ritmo\Infrastructure\Db\Database;
use Ritmo\Infrastructure\Db\DbValue;

/**
 * The alerts table with its lifecycle (the columns of the operations migration): lookup by key
 * over every status, refresh in place, acknowledgement and resolution by whom, e-mail and digest
 * bookkeeping. Decisions (dedupe, reopen, who is told) are the domain's; this only stores.
 */
final class AlertLedger
{
    private const TABLE = 'alerts';

    public function __construct(private readonly Database $db)
    {
    }

    /**
     * The newest alert stored under a dedupe key, whatever its status.
     */
    public function latestByKey(string $dedupeKey): ?AlertRecord
    {
        $row = $this->db->selectRow(sprintf('SELECT * FROM %s WHERE dedupe_key = ? ORDER BY id DESC LIMIT 1', $this->db->table(self::TABLE)), [$dedupeKey]);

        return $row === null ? null : $this->hydrate($row);
    }

    public function find(int $id): ?AlertRecord
    {
        $row = $this->db->selectRow(sprintf('SELECT * FROM %s WHERE id = ?', $this->db->table(self::TABLE)), [$id]);

        return $row === null ? null : $this->hydrate($row);
    }

    public function insert(AlertDraft $draft, ?int $runId, DateTimeImmutable $now): AlertRecord
    {
        $id = $this->db->insert(self::TABLE, $this->values($draft, $runId, $now) + [
            'status' => AlertStatus::Open->value,
            'created_at' => DbValue::instant($now),
            'evidence_at' => DbValue::nullableInstant($draft->evidenceAt),
            'occurrences' => 1,
        ]);

        return $this->find($id) ?? throw new \LogicException('The alert just inserted is missing.');
    }

    /**
     * Refreshes an open or acknowledged alert: message, facts, severity, run, the newest evidence
     * and one more occurrence. The status and the e-mail bookkeeping stay.
     */
    public function refresh(AlertRecord $alert, AlertDraft $draft, ?int $runId, DateTimeImmutable $now): AlertRecord
    {
        $evidence = $draft->evidenceAt !== null && ($alert->evidenceAt === null || $draft->evidenceAt > $alert->evidenceAt) ? $draft->evidenceAt : $alert->evidenceAt;
        $this->db->update(self::TABLE, $this->values($draft, $runId, $now) + [
            'evidence_at' => DbValue::nullableInstant($evidence),
            'occurrences' => $alert->occurrences + 1,
        ], ['id' => $alert->id]);

        return $this->find($alert->id) ?? throw new \LogicException('The alert just refreshed is missing.');
    }

    /**
     * The open or acknowledged alerts of a property with one of the codes.
     *
     * @param list<string> $codes
     *
     * @return list<AlertRecord>
     */
    public function active(string $propertyId, array $codes): array
    {
        if ($codes === []) {
            return [];
        }
        $sql = sprintf(
            'SELECT * FROM %s WHERE property_code = ? AND status <> ? AND code IN (%s) ORDER BY id',
            $this->db->table(self::TABLE),
            implode(', ', array_fill(0, count($codes), '?'))
        );

        return array_map($this->hydrate(...), $this->db->select($sql, [$propertyId, AlertStatus::Resolved->value, ...$codes]));
    }

    public function acknowledge(int $id, int $userId, DateTimeImmutable $now): void
    {
        $this->db->update(self::TABLE, [
            'status' => AlertStatus::Acknowledged->value,
            'acknowledged_at' => DbValue::instant($now),
            'acknowledged_by' => $userId,
            'updated_at' => DbValue::instant($now),
        ], ['id' => $id, 'status' => AlertStatus::Open->value]);
    }

    /**
     * @param int|null $userId null when the system resolves it (the condition is gone)
     */
    public function resolve(int $id, ?int $userId, DateTimeImmutable $now): void
    {
        $this->db->execute(
            sprintf('UPDATE %s SET status = ?, resolved_at = ?, resolved_by = ?, updated_at = ? WHERE id = ? AND status <> ?', $this->db->table(self::TABLE)),
            [AlertStatus::Resolved->value, DbValue::instant($now), $userId, DbValue::instant($now), $id, AlertStatus::Resolved->value]
        );
    }

    public function markNotified(int $id, DateTimeImmutable $now): void
    {
        $this->db->update(self::TABLE, ['notified_at' => DbValue::instant($now)], ['id' => $id]);
    }

    /**
     * How many alerts of a property were e-mailed at or after $since.
     */
    public function notifiedSince(string $propertyId, DateTimeImmutable $since): int
    {
        return (int) $this->db->selectValue(
            sprintf('SELECT COUNT(*) FROM %s WHERE property_code = ? AND notified_at >= ?', $this->db->table(self::TABLE)),
            [$propertyId, DbValue::instant($since)]
        );
    }

    /**
     * The open or acknowledged warnings and critical alerts of a property that were neither
     * e-mailed nor put in a digest yet, oldest first.
     *
     * @return list<AlertRecord>
     */
    public function pendingDigest(string $propertyId): array
    {
        return array_map($this->hydrate(...), $this->db->select(
            sprintf('SELECT * FROM %s WHERE property_code = ? AND status <> ? AND severity IN (?, ?) AND notified_at IS NULL AND digested_at IS NULL ORDER BY id', $this->db->table(self::TABLE)),
            [$propertyId, AlertStatus::Resolved->value, AlertSeverity::Critical->value, AlertSeverity::Warning->value]
        ));
    }

    /**
     * @param list<int> $ids
     */
    public function markDigested(array $ids, DateTimeImmutable $now): void
    {
        foreach ($ids as $id) {
            $this->db->update(self::TABLE, ['digested_at' => DbValue::instant($now)], ['id' => $id]);
        }
    }

    /**
     * @return array<string, int|float|string|null>
     */
    private function values(AlertDraft $draft, ?int $runId, DateTimeImmutable $now): array
    {
        return [
            'property_code' => $draft->propertyId,
            'room_code' => $draft->roomCode,
            'night' => DbValue::nullableNight($draft->night),
            'code' => $draft->code->value,
            'severity' => $draft->severity->value,
            'message' => $draft->message,
            'context' => DbValue::json($draft->context),
            'dedupe_key' => $draft->dedupeKey(),
            'run_id' => $runId,
            'updated_at' => DbValue::instant($now),
        ];
    }

    /**
     * @param array<string, string|null> $row
     */
    private function hydrate(array $row): AlertRecord
    {
        return new AlertRecord(
            (int) $row['id'],
            (string) $row['code'],
            AlertSeverity::from((string) $row['severity']),
            AlertStatus::from((string) $row['status']),
            (string) $row['property_code'],
            (string) $row['room_code'],
            DbValue::toNight($row['night']),
            (string) $row['message'],
            DbValue::toArray($row['context']),
            (string) $row['dedupe_key'],
            DbValue::nullableInt($row['run_id']),
            DbValue::requireInstant($row['created_at']),
            DbValue::requireInstant($row['updated_at']),
            DbValue::toInstant($row['resolved_at']),
            DbValue::toInstant($row['evidence_at']),
            DbValue::toInstant($row['notified_at']),
            DbValue::toInstant($row['digested_at']),
            (int) ($row['occurrences'] ?? 1),
        );
    }
}
