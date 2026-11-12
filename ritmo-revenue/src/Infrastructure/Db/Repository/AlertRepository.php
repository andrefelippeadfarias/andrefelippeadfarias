<?php

declare(strict_types=1);

namespace Ritmo\Infrastructure\Db\Repository;

use DateTimeImmutable;
use InvalidArgumentException;
use Ritmo\Domain\Support\Clock;
use Ritmo\Infrastructure\Db\Database;
use Ritmo\Infrastructure\Db\DbValue;

/**
 * Alerts for the person who runs the property. An alert is open until someone acknowledges
 * or resolves it.
 */
final class AlertRepository
{
    public const INFO = 'info';
    public const WARNING = 'warning';
    public const CRITICAL = 'critical';

    public const OPEN = 'open';
    public const ACKNOWLEDGED = 'acknowledged';
    public const RESOLVED = 'resolved';

    private const TABLE = 'alerts';

    public function __construct(private readonly Database $db, private readonly Clock $clock)
    {
    }

    /**
     * Raises an alert. With a $dedupeKey, an alert that is still open under the same key is
     * refreshed instead of repeated, so a condition that persists run after run shows once.
     *
     * @param string               $code      kind of alert ("booking_below_floor")
     * @param string               $severity  self::INFO, self::WARNING or self::CRITICAL
     * @param string               $message   English sentence safe to show; no guest data
     * @param array<string, mixed> $context   facts behind the alert; no guest data
     * @param string               $dedupeKey identifies the condition ("below_floor:ROOM:2026-12-24"); empty to always add
     *
     * @return int id of the alert raised or refreshed
     *
     * @throws InvalidArgumentException on an unknown severity
     */
    public function raise(
        string $code,
        string $severity,
        string $message,
        array $context = [],
        string $propertyId = '',
        string $roomCode = '',
        ?DateTimeImmutable $night = null,
        string $dedupeKey = '',
        ?int $runId = null,
    ): int {
        if (!in_array($severity, [self::INFO, self::WARNING, self::CRITICAL], true)) {
            throw new InvalidArgumentException(sprintf('Unknown alert severity "%s".', $severity));
        }

        return $this->db->transaction(function () use ($code, $severity, $message, $context, $propertyId, $roomCode, $night, $dedupeKey, $runId): int {
            $now = DbValue::instant($this->clock->now());
            $row = [
                'property_code' => $propertyId,
                'room_code' => $roomCode,
                'night' => DbValue::nullableNight($night),
                'code' => DbValue::word($code, 64),
                'severity' => $severity,
                'message' => $message,
                'context' => DbValue::json($context),
                'dedupe_key' => DbValue::reference($dedupeKey, 190),
                'run_id' => $runId,
                'updated_at' => $now,
            ];

            $existing = $dedupeKey === '' ? null : $this->db->selectValue(
                sprintf('SELECT id FROM %s WHERE dedupe_key = ? AND status = ? ORDER BY id DESC LIMIT 1', $this->db->table(self::TABLE)),
                [$dedupeKey, self::OPEN]
            );
            if ($existing !== null) {
                $this->db->update(self::TABLE, $row, ['id' => (int) $existing]);

                return (int) $existing;
            }

            return $this->db->insert(self::TABLE, $row + ['status' => self::OPEN, 'created_at' => $now]);
        });
    }

    /**
     * @return array{id: int, property_id: string, room_code: string, night: DateTimeImmutable|null, code: string, severity: string, message: string, context: array<string, mixed>, status: string, dedupe_key: string, run_id: int|null, created_at: DateTimeImmutable, updated_at: DateTimeImmutable, resolved_at: DateTimeImmutable|null}|null
     */
    public function find(int $id): ?array
    {
        $row = $this->db->selectRow(sprintf('SELECT * FROM %s WHERE id = ?', $this->db->table(self::TABLE)), [$id]);

        return $row === null ? null : $this->hydrate($row);
    }

    /**
     * The alerts nobody resolved yet (open or acknowledged), newest first.
     *
     * @param string|null $propertyId only this property; null for every property
     *
     * @return list<array{id: int, property_id: string, room_code: string, night: DateTimeImmutable|null, code: string, severity: string, message: string, context: array<string, mixed>, status: string, dedupe_key: string, run_id: int|null, created_at: DateTimeImmutable, updated_at: DateTimeImmutable, resolved_at: DateTimeImmutable|null}>
     */
    public function unresolved(?string $propertyId = null, int $limit = 100): array
    {
        $sql = sprintf('SELECT * FROM %s WHERE status <> ?', $this->db->table(self::TABLE));
        $params = [self::RESOLVED];
        if ($propertyId !== null) {
            $sql .= ' AND property_code = ?';
            $params[] = $propertyId;
        }
        $params[] = max(1, $limit);

        return array_map($this->hydrate(...), $this->db->select($sql . ' ORDER BY updated_at DESC, id DESC LIMIT ?', $params));
    }

    public function countOpen(?string $propertyId = null): int
    {
        $sql = sprintf('SELECT COUNT(*) FROM %s WHERE status = ?', $this->db->table(self::TABLE));
        $params = [self::OPEN];
        if ($propertyId !== null) {
            $sql .= ' AND property_code = ?';
            $params[] = $propertyId;
        }

        return (int) $this->db->selectValue($sql, $params);
    }

    /**
     * Marks an alert as seen; it stays in the list until resolved.
     */
    public function acknowledge(int $id): void
    {
        $this->db->update(self::TABLE, ['status' => self::ACKNOWLEDGED, 'updated_at' => DbValue::instant($this->clock->now())], ['id' => $id, 'status' => self::OPEN]);
    }

    public function resolve(int $id): void
    {
        $now = DbValue::instant($this->clock->now());
        $this->db->execute(
            sprintf('UPDATE %s SET status = ?, updated_at = ?, resolved_at = ? WHERE id = ? AND status <> ?', $this->db->table(self::TABLE)),
            [self::RESOLVED, $now, $now, $id, self::RESOLVED]
        );
    }

    /**
     * @param array<string, string|null> $row
     *
     * @return array{id: int, property_id: string, room_code: string, night: DateTimeImmutable|null, code: string, severity: string, message: string, context: array<string, mixed>, status: string, dedupe_key: string, run_id: int|null, created_at: DateTimeImmutable, updated_at: DateTimeImmutable, resolved_at: DateTimeImmutable|null}
     */
    private function hydrate(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'property_id' => (string) $row['property_code'],
            'room_code' => (string) $row['room_code'],
            'night' => DbValue::toNight($row['night']),
            'code' => (string) $row['code'],
            'severity' => (string) $row['severity'],
            'message' => (string) $row['message'],
            'context' => DbValue::toArray($row['context']),
            'status' => (string) $row['status'],
            'dedupe_key' => (string) $row['dedupe_key'],
            'run_id' => DbValue::nullableInt($row['run_id']),
            'created_at' => DbValue::requireInstant($row['created_at']),
            'updated_at' => DbValue::requireInstant($row['updated_at']),
            'resolved_at' => DbValue::toInstant($row['resolved_at']),
        ];
    }
}
