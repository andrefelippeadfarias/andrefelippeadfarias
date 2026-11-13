<?php

declare(strict_types=1);

namespace Ritmo\Infrastructure\Db\Repository;

use DateTimeImmutable;
use Ritmo\Infrastructure\Db\Database;
use Ritmo\Infrastructure\Db\DbValue;

/**
 * Recalculation attempts in the quota log (api_quota_log, action "recalculation"), each with its
 * outcome: recorded as "pending" before the call and settled after it. Rows are plain arrays;
 * the write path turns them into its own attempts.
 */
final class RecalcAttemptRepository
{
    public const ACTION = 'recalculation';

    private const TABLE = 'api_quota_log';

    private const ERROR_LENGTH = 300;

    public function __construct(private readonly Database $db)
    {
    }

    /**
     * @return int id of the attempt
     */
    public function record(string $provider, string $listing, string $outcome, DateTimeImmutable $at, ?string $error = null, string $responseId = ''): int
    {
        return $this->db->insert(self::TABLE, [
            'provider' => DbValue::word($provider, 32),
            'action' => self::ACTION,
            'scope' => DbValue::reference($listing, 64),
            'succeeded' => $outcome === 'ok',
            'outcome' => DbValue::word($outcome, 16),
            'response_id' => DbValue::reference($responseId, 64),
            'error' => $error === null ? null : mb_substr($error, 0, self::ERROR_LENGTH, 'UTF-8'),
            'called_at' => DbValue::instant($at),
        ]);
    }

    public function settle(int $id, string $outcome, ?string $error = null): void
    {
        $this->db->update(self::TABLE, [
            'succeeded' => $outcome === 'ok',
            'outcome' => DbValue::word($outcome, 16),
            'error' => $error === null ? null : mb_substr($error, 0, self::ERROR_LENGTH, 'UTF-8'),
        ], ['id' => $id, 'action' => self::ACTION]);
    }

    /**
     * Attempts of the provider at or after the instant, oldest first.
     *
     * @return list<array{id: int, listing: string, outcome: string, at: DateTimeImmutable, error: string|null, response_id: string}>
     */
    public function since(string $provider, DateTimeImmutable $since): array
    {
        $rows = $this->db->select(
            sprintf('SELECT id, scope, outcome, called_at, error, response_id FROM %s WHERE provider = ? AND action = ? AND called_at >= ? ORDER BY called_at, id', $this->db->table(self::TABLE)),
            [$provider, self::ACTION, DbValue::instant($since)]
        );

        return array_map(static fn (array $row): array => [
            'id' => (int) $row['id'],
            'listing' => (string) $row['scope'],
            'outcome' => (string) $row['outcome'],
            'at' => DbValue::requireInstant($row['called_at']),
            'error' => $row['error'] === null ? null : (string) $row['error'],
            'response_id' => (string) $row['response_id'],
        ], $rows);
    }
}
