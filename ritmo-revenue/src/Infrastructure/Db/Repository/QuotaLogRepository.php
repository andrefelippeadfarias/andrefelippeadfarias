<?php

declare(strict_types=1);

namespace Ritmo\Infrastructure\Db\Repository;

use DateTimeImmutable;
use Ritmo\Infrastructure\Db\Database;
use Ritmo\Infrastructure\Db\DbValue;

/**
 * The log of metered calls to external systems (table "api_quota_log"): one row per attempt,
 * successful or not.
 *
 * A scope is what a quota is counted for (a listing). Reading with the scope "" means the
 * whole account: every call of the provider and action, whatever its scope.
 */
final class QuotaLogRepository
{
    private const TABLE = 'api_quota_log';

    public function __construct(private readonly Database $db)
    {
    }

    /**
     * @return int id of the entry
     */
    public function add(string $provider, string $action, string $scope, bool $succeeded, DateTimeImmutable $at): int
    {
        // Kept to the second, rounded up: with the window start rounded down when reading, a
        // window can count a call up to a second too long but never forgets one early.
        $whole = (int) $at->format('u') === 0 ? $at : $at->modify('+1 second');

        return $this->db->insert(self::TABLE, [
            'provider' => DbValue::word($provider, 32),
            'action' => DbValue::word($action, 64),
            'scope' => DbValue::reference($scope, 64),
            'succeeded' => $succeeded,
            'called_at' => DbValue::instant($whole),
        ]);
    }

    /**
     * Instants of the calls made at or after $since (or strictly after it), oldest first.
     *
     * @param string $scope     a scope, or "" for the whole account
     * @param bool   $inclusive false to leave out the calls made exactly at $since
     *
     * @return list<DateTimeImmutable>
     */
    public function instants(string $provider, string $action, string $scope, DateTimeImmutable $since, bool $inclusive = true): array
    {
        $sql = sprintf(
            'SELECT called_at FROM %s WHERE provider = ? AND action = ? AND called_at %s ?',
            $this->db->table(self::TABLE),
            $inclusive ? '>=' : '>'
        );
        $params = [$provider, $action, DbValue::instant($since)];
        if ($scope !== '') {
            $sql .= ' AND scope = ?';
            $params[] = $scope;
        }

        return array_map(
            static fn (array $row): DateTimeImmutable => DbValue::requireInstant($row['called_at']),
            $this->db->select($sql . ' ORDER BY called_at, id', $params)
        );
    }

    /**
     * The latest entries, newest first, for the diagnostics screen.
     *
     * @return list<array{id: int, provider: string, action: string, scope: string, succeeded: bool, called_at: DateTimeImmutable}>
     */
    public function recent(int $limit = 50): array
    {
        $rows = $this->db->select(
            sprintf('SELECT * FROM %s ORDER BY called_at DESC, id DESC LIMIT ?', $this->db->table(self::TABLE)),
            [max(1, $limit)]
        );

        return array_map(static fn (array $row): array => [
            'id' => (int) $row['id'],
            'provider' => (string) $row['provider'],
            'action' => (string) $row['action'],
            'scope' => (string) $row['scope'],
            'succeeded' => (bool) (int) $row['succeeded'],
            'called_at' => DbValue::requireInstant($row['called_at']),
        ], $rows);
    }

    /**
     * Removes the entries older than an instant. Keep at least the longest quota window.
     *
     * @return int rows removed
     */
    public function purgeBefore(DateTimeImmutable $instant): int
    {
        return $this->db->execute(
            sprintf('DELETE FROM %s WHERE called_at < ?', $this->db->table(self::TABLE)),
            [DbValue::instant($instant)]
        );
    }
}
