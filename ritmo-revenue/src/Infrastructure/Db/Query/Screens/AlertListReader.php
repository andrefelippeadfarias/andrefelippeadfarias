<?php

declare(strict_types=1);

namespace Ritmo\Infrastructure\Db\Query\Screens;

use Ritmo\Application\Dashboard\Alerts\AlertFilter;
use Ritmo\Application\Dashboard\Alerts\AlertList;
use Ritmo\Domain\Support\Days;
use Ritmo\Infrastructure\Db\Database;
use Ritmo\Infrastructure\Db\DbValue;

/**
 * Pages of the alerts table for the Alerts screen, filtered and newest first, with their whole
 * lifecycle (who acknowledged and resolved, occurrences). Prepared statements only.
 */
final class AlertListReader implements AlertList
{
    private const TABLE = 'alerts';

    public function __construct(private readonly Database $db)
    {
    }

    public function count(AlertFilter $filter): int
    {
        [$where, $params] = $this->where($filter);

        return (int) $this->db->selectValue(sprintf('SELECT COUNT(*) FROM %s WHERE %s', $this->db->table(self::TABLE), $where), $params);
    }

    public function page(AlertFilter $filter, int $offset, int $limit): array
    {
        [$where, $params] = $this->where($filter);
        $params[] = max(1, $limit);
        $params[] = max(0, $offset);

        return array_map(self::row(...), $this->db->select(
            sprintf('SELECT * FROM %s WHERE %s ORDER BY updated_at DESC, id DESC LIMIT ? OFFSET ?', $this->db->table(self::TABLE), $where),
            $params
        ));
    }

    public function find(int $id): ?array
    {
        $row = $this->db->selectRow(sprintf('SELECT * FROM %s WHERE id = ?', $this->db->table(self::TABLE)), [$id]);

        return $row === null ? null : self::row($row);
    }

    public function countBySeverity(?string $propertyId, array $severities): int
    {
        $sql = sprintf('SELECT COUNT(*) FROM %s WHERE status = ? AND severity IN (%s)', $this->db->table(self::TABLE), implode(', ', array_fill(0, max(1, count($severities)), '?')));
        $params = ['open', ...($severities === [] ? [''] : $severities)];
        if ($propertyId !== null) {
            $sql .= ' AND property_code = ?';
            $params[] = $propertyId;
        }

        return (int) $this->db->selectValue($sql, $params);
    }

    /**
     * @return array{0: string, 1: list<string|int>}
     */
    private function where(AlertFilter $filter): array
    {
        $clauses = ['1 = 1'];
        $params = [];
        if ($filter->propertyId !== '') {
            $clauses[] = 'property_code = ?';
            $params[] = $filter->propertyId;
        }
        if ($filter->status === 'unresolved') {
            $clauses[] = 'status <> ?';
            $params[] = 'resolved';
        } elseif ($filter->status !== 'all') {
            $clauses[] = 'status = ?';
            $params[] = $filter->status;
        }
        if ($filter->severity !== '') {
            $clauses[] = 'severity = ?';
            $params[] = $filter->severity;
        }
        $codes = $filter->codes();
        if ($codes !== []) {
            $clauses[] = sprintf('code IN (%s)', implode(', ', array_fill(0, count($codes), '?')));
            array_push($params, ...$codes);
        }
        if ($filter->from !== null) {
            $clauses[] = 'created_at >= ?';
            $params[] = DbValue::instant($filter->from);
        }
        if ($filter->to !== null) {
            $clauses[] = 'created_at < ?';
            $params[] = DbValue::instant(Days::add($filter->to, 1));
        }

        return [implode(' AND ', $clauses), $params];
    }

    /**
     * @param array<string, string|null> $row
     *
     * @return array<string, mixed>
     */
    private static function row(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'property_id' => (string) $row['property_code'],
            'room_code' => (string) $row['room_code'],
            'night' => DbValue::toNight($row['night']),
            'code' => (string) $row['code'],
            'severity' => (string) $row['severity'],
            'context' => DbValue::toArray($row['context']),
            'status' => (string) $row['status'],
            'run_id' => DbValue::nullableInt($row['run_id']),
            'first_seen' => DbValue::requireInstant($row['created_at']),
            'last_seen' => DbValue::requireInstant($row['updated_at']),
            'acknowledged_at' => DbValue::toInstant($row['acknowledged_at'] ?? null),
            'acknowledged_by' => DbValue::nullableInt($row['acknowledged_by'] ?? null),
            'resolved_at' => DbValue::toInstant($row['resolved_at']),
            'resolved_by' => DbValue::nullableInt($row['resolved_by'] ?? null),
            'occurrences' => (int) ($row['occurrences'] ?? 1),
        ];
    }
}
