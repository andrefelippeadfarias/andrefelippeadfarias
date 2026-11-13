<?php

declare(strict_types=1);

namespace Ritmo\Infrastructure\Db\Query\Screens;

use Ritmo\Application\Dashboard\Changes\ChangeFilter;
use Ritmo\Application\Dashboard\Changes\ChangeList;
use Ritmo\Domain\Support\Days;
use Ritmo\Infrastructure\Db\Database;
use Ritmo\Infrastructure\Db\DbValue;
use Ritmo\Infrastructure\Db\Repository\ChangeLogRepository;

/**
 * Filtered pages of the change log, newest first: the ids of a page are read with the filter,
 * then the rows through the repository (one hydration path for change records).
 */
final class ChangeLogReader implements ChangeList
{
    private const TABLE = 'change_log';

    public function __construct(private readonly Database $db, private readonly ChangeLogRepository $changes)
    {
    }

    public function count(ChangeFilter $filter): int
    {
        [$where, $params] = $this->where($filter);

        return (int) $this->db->selectValue(sprintf('SELECT COUNT(*) FROM %s WHERE %s', $this->db->table(self::TABLE), $where), $params);
    }

    public function page(ChangeFilter $filter, int $offset, int $limit): array
    {
        [$where, $params] = $this->where($filter);
        $params[] = max(1, $limit);
        $params[] = max(0, $offset);
        $ids = array_map(static fn (array $r): int => (int) $r['id'], $this->db->select(
            sprintf('SELECT id FROM %s WHERE %s ORDER BY created_at DESC, id DESC LIMIT ? OFFSET ?', $this->db->table(self::TABLE), $where),
            $params
        ));

        return $ids === [] ? [] : array_reverse($this->changes->byIds($ids));
    }

    /**
     * @return array{0: string, 1: list<string|int>}
     */
    private function where(ChangeFilter $filter): array
    {
        $clauses = ['property_code = ?', "provider <> ''"];
        $params = [$filter->propertyId];
        $optional = [
            'room_code = ?' => $filter->room === '' ? null : $filter->room,
            'night >= ?' => $filter->nightFrom === null ? null : DbValue::night($filter->nightFrom),
            'night <= ?' => $filter->nightTo === null ? null : DbValue::night($filter->nightTo),
            'created_at >= ?' => $filter->changedFrom === null ? null : DbValue::instant($filter->changedFrom),
            'created_at < ?' => $filter->changedTo === null ? null : DbValue::instant(Days::add($filter->changedTo, 1)),
            'provider = ?' => $filter->system === '' ? null : $filter->system,
        ];
        foreach ($optional as $clause => $value) {
            if ($value !== null) {
                $clauses[] = $clause;
                $params[] = $value;
            }
        }
        $clauses = [...$clauses, ...match ($filter->who) {
            'autonomous' => ["actor = 'autonomous'", 'undo_of IS NULL'],
            'person' => ["actor LIKE 'user:%'", 'undo_of IS NULL'],
            'undo' => ['undo_of IS NOT NULL'],
            default => [],
        }];
        if ($filter->notUndone) {
            $clauses[] = 'undone_at IS NULL';
        }

        return [implode(' AND ', $clauses), $params];
    }
}
