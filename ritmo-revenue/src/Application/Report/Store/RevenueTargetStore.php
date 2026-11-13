<?php

declare(strict_types=1);

namespace Ritmo\Application\Report\Store;

use DateTimeImmutable;
use Ritmo\Infrastructure\Db\Database;
use Ritmo\Infrastructure\Db\DbValue;

/**
 * The owner's monthly targets (table revenue_targets). Reads and writes with prepared statements
 * only. A save returns the row it replaced, so the caller writes the change log (old -> new).
 */
final class RevenueTargetStore
{
    public const TABLE = 'revenue_targets';

    public function __construct(private readonly Database $db)
    {
    }

    /**
     * @param list<string> $months "YYYY-MM"
     *
     * @return array<string, TargetRow> month => row; months never typed are absent
     */
    public function forProperty(string $propertyId, array $months): array
    {
        if ($months === []) {
            return [];
        }
        $marks = implode(', ', array_fill(0, count($months), '?'));
        $rows = $this->db->select(
            sprintf('SELECT * FROM %s WHERE property_code = ? AND month IN (%s) ORDER BY month', $this->db->table(self::TABLE), $marks),
            [$propertyId, ...$months]
        );
        $out = [];
        foreach ($rows as $row) {
            $values = [];
            foreach (TargetRow::FIELDS as $field) {
                $values[$field] = DbValue::nullableFloat(isset($row[$field]) ? (string) $row[$field] : null);
            }
            $out[(string) $row['month']] = new TargetRow((string) $row['month'], $values);
        }

        return $out;
    }

    /**
     * @return TargetRow|null the row before the save (null: the month was not typed)
     */
    public function save(string $propertyId, TargetRow $row, int $userId, DateTimeImmutable $now): ?TargetRow
    {
        $before = $this->forProperty($propertyId, [$row->month])[$row->month] ?? null;
        $values = ['occupancy_cap' => 1.0, 'adjust_floor' => 0.0, 'adjust_base' => 0.0, 'adjust_goal' => 0.0];
        foreach (TargetRow::FIELDS as $field) {
            $values[$field] = $row->value($field) ?? ($values[$field] ?? null);
        }
        $this->db->upsert(self::TABLE, [
            'property_code' => $propertyId,
            'month' => $row->month,
            ...$values,
            'updated_by' => $userId,
            'updated_at' => DbValue::instant($now),
        ], ['property_code', 'month']);

        return $before;
    }
}
