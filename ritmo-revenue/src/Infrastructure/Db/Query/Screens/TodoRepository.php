<?php

declare(strict_types=1);

namespace Ritmo\Infrastructure\Db\Query\Screens;

use DateTimeImmutable;
use Ritmo\Application\Dashboard\Todo\TodoItem;
use Ritmo\Application\Dashboard\Todo\TodoStore;
use Ritmo\Infrastructure\Db\Database;
use Ritmo\Infrastructure\Db\DbValue;

/**
 * The to-do items in pending_items (columns of the DraftScreens migration). "details" keeps the
 * text a person typed and the parameters of a plugin item as JSON. Prepared statements only.
 */
final class TodoRepository implements TodoStore
{
    private const TABLE = 'pending_items';

    public function __construct(private readonly Database $db)
    {
    }

    public function add(TodoItem $item, DateTimeImmutable $now, bool $once = false): int
    {
        if ($item->dedupeKey !== '') {
            $sql = sprintf('SELECT id FROM %s WHERE dedupe_key = ?%s ORDER BY id DESC LIMIT 1', $this->db->table(self::TABLE), $once ? '' : ' AND status = ?');
            $existing = $this->db->selectValue($sql, $once ? [$item->dedupeKey] : [$item->dedupeKey, TodoItem::OPEN]);
            if ($existing !== null) {
                return (int) $existing;
            }
        }

        return $this->db->insert(self::TABLE, [
            'property_code' => $item->propertyId,
            'kind' => DbValue::word($item->kind, 32),
            'title' => DbValue::clip($item->title, 190),
            'details' => DbValue::json(['text' => $item->details, 'params' => $item->params]),
            'status' => TodoItem::OPEN,
            'due_on' => DbValue::nullableNight($item->dueOn),
            'created_at' => DbValue::instant($now),
            'updated_at' => DbValue::instant($now),
            'source' => $item->source,
            'dedupe_key' => DbValue::clip($item->dedupeKey, 190),
            'room_code' => $item->roomCode,
            'night' => DbValue::nullableNight($item->night),
            'created_by' => $item->createdBy,
        ]);
    }

    public function find(int $id): ?TodoItem
    {
        $row = $this->db->selectRow(sprintf('SELECT * FROM %s WHERE id = ?', $this->db->table(self::TABLE)), [$id]);

        return $row === null ? null : self::item($row);
    }

    public function open(?string $propertyId, int $limit = 200): array
    {
        [$where, $params] = self::scope($propertyId);
        $params = [TodoItem::OPEN, ...$params, max(1, $limit)];

        return array_map(self::item(...), $this->db->select(
            sprintf('SELECT * FROM %s WHERE status = ?%s ORDER BY CASE WHEN due_on IS NULL THEN 1 ELSE 0 END, due_on, created_at, id LIMIT ?', $this->db->table(self::TABLE), $where),
            $params
        ));
    }

    public function done(?string $propertyId, int $limit = 50): array
    {
        [$where, $params] = self::scope($propertyId);
        $params = [TodoItem::OPEN, ...$params, max(1, $limit)];

        return array_map(self::item(...), $this->db->select(
            sprintf('SELECT * FROM %s WHERE status <> ?%s ORDER BY closed_at DESC, id DESC LIMIT ?', $this->db->table(self::TABLE), $where),
            $params
        ));
    }

    public function setStatus(int $id, string $status, int $userId, DateTimeImmutable $now): bool
    {
        $closing = $status !== TodoItem::OPEN;

        return $this->db->update(self::TABLE, [
            'status' => $status,
            'updated_at' => DbValue::instant($now),
            'closed_at' => $closing ? DbValue::instant($now) : null,
            'closed_by' => $closing ? $userId : null,
        ], ['id' => $id]) === 1;
    }

    public function closeKeys(array $keys, DateTimeImmutable $now): int
    {
        $closed = 0;
        foreach (array_unique($keys) as $key) {
            $closed += $this->db->execute(
                sprintf('UPDATE %s SET status = ?, updated_at = ?, closed_at = ? WHERE dedupe_key = ? AND status = ? AND source = ?', $this->db->table(self::TABLE)),
                [TodoItem::DONE, DbValue::instant($now), DbValue::instant($now), $key, TodoItem::OPEN, TodoItem::PLUGIN]
            );
        }

        return $closed;
    }

    public function openKeys(string $kind): array
    {
        return array_map(static fn (array $r): string => (string) $r['dedupe_key'], $this->db->select(
            sprintf("SELECT dedupe_key FROM %s WHERE kind = ? AND status = ? AND source = ? AND dedupe_key <> ''", $this->db->table(self::TABLE)),
            [$kind, TodoItem::OPEN, TodoItem::PLUGIN]
        ));
    }

    public function countDue(?string $propertyId, DateTimeImmutable $today): int
    {
        [$where, $params] = self::scope($propertyId);

        return (int) $this->db->selectValue(
            sprintf('SELECT COUNT(*) FROM %s WHERE status = ? AND (due_on IS NULL OR due_on <= ?)%s', $this->db->table(self::TABLE), $where),
            [TodoItem::OPEN, DbValue::night($today), ...$params]
        );
    }

    /**
     * @return array{0: string, 1: list<string>}
     */
    private static function scope(?string $propertyId): array
    {
        return $propertyId === null ? ['', []] : [" AND (property_code = ? OR property_code = '')", [$propertyId]];
    }

    /**
     * @param array<string, string|null> $row
     */
    private static function item(array $row): TodoItem
    {
        $details = DbValue::toArray($row['details']);
        $params = is_array($details['params'] ?? null) ? array_filter($details['params'], static fn (mixed $v): bool => is_string($v) || is_int($v)) : [];

        return new TodoItem(
            (string) $row['property_code'],
            (string) ($row['source'] ?? TodoItem::PERSON),
            (string) $row['kind'],
            (string) $row['title'],
            is_string($details['text'] ?? null) ? $details['text'] : '',
            DbValue::toNight($row['due_on']),
            (string) ($row['room_code'] ?? ''),
            DbValue::toNight($row['night'] ?? null),
            (string) ($row['dedupe_key'] ?? ''),
            $params,
            (int) ($row['created_by'] ?? 0),
            (string) $row['status'],
            DbValue::toInstant($row['created_at']),
            DbValue::toInstant($row['closed_at']),
            DbValue::nullableInt($row['closed_by'] ?? null),
            (int) $row['id'],
        );
    }
}
