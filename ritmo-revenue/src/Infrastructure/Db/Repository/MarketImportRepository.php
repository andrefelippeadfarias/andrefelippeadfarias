<?php

declare(strict_types=1);

namespace Ritmo\Infrastructure\Db\Repository;

use DateTimeImmutable;
use Ritmo\Infrastructure\Db\Database;
use Ritmo\Infrastructure\Db\DbValue;

/**
 * One row per imported market price file: who, the file's name and hash, the counts and at most
 * 200 problems as {line, column, code}. Never the file, never a cell's content.
 */
final class MarketImportRepository
{
    public const MAX_PROBLEMS = 200;

    private const TABLE = 'market_imports';

    public function __construct(private readonly Database $db)
    {
    }

    /**
     * @param array{rows_total: int, rows_imported: int, rows_replaced: int, rows_invalid: int, rows_warned: int} $counts
     * @param list<array{line: int, column: string, code: string}>                                               $problems
     */
    public function record(string $propertyId, int $userId, string $fileName, string $sha256, int $bytes, array $counts, array $problems, DateTimeImmutable $at): int
    {
        return $this->db->insert(self::TABLE, [
            'property_code' => $propertyId,
            'user_id' => max(0, $userId),
            'file_name' => DbValue::clip($fileName, 120),
            'file_sha256' => DbValue::word($sha256, 64),
            'bytes' => max(0, $bytes),
            'problems' => DbValue::json(array_slice($problems, 0, self::MAX_PROBLEMS)),
            'created_at' => DbValue::instant($at),
        ] + $counts);
    }

    /**
     * Sets the counts known only once the rows are stored.
     */
    public function settle(int $id, int $imported, int $replaced): void
    {
        $this->db->update(self::TABLE, ['rows_imported' => $imported, 'rows_replaced' => $replaced], ['id' => $id]);
    }

    /**
     * When the same file was imported for the property before; null when never.
     */
    public function importedAt(string $propertyId, string $sha256): ?DateTimeImmutable
    {
        return DbValue::toInstant($this->db->selectValue(
            sprintf('SELECT MIN(created_at) FROM %s WHERE property_code = ? AND file_sha256 = ?', $this->db->table(self::TABLE)),
            [$propertyId, $sha256]
        ));
    }

    /**
     * The latest imports of a property, newest first.
     *
     * @return list<array{id: int, user_id: int, file_name: string, rows_total: int, rows_imported: int, rows_replaced: int, rows_invalid: int, rows_warned: int, created_at: DateTimeImmutable}>
     */
    public function latest(string $propertyId, int $limit = 10): array
    {
        return array_map(static fn (array $row): array => [
            'id' => (int) $row['id'],
            'user_id' => (int) $row['user_id'],
            'file_name' => (string) $row['file_name'],
            'rows_total' => (int) $row['rows_total'],
            'rows_imported' => (int) $row['rows_imported'],
            'rows_replaced' => (int) $row['rows_replaced'],
            'rows_invalid' => (int) $row['rows_invalid'],
            'rows_warned' => (int) $row['rows_warned'],
            'created_at' => DbValue::requireInstant($row['created_at']),
        ], $this->db->select(
            sprintf('SELECT * FROM %s WHERE property_code = ? ORDER BY created_at DESC, id DESC LIMIT ?', $this->db->table(self::TABLE)),
            [$propertyId, max(1, min(100, $limit))]
        ));
    }
}
