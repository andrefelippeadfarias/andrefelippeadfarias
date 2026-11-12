<?php

declare(strict_types=1);

namespace Ritmo\Infrastructure\Db\Repository;

use DateTimeImmutable;
use InvalidArgumentException;
use OutOfBoundsException;
use Ritmo\Domain\Model\PropertyMode;
use Ritmo\Infrastructure\Db\Database;
use Ritmo\Infrastructure\Db\DbValue;

/**
 * The runs of the pipeline: one row when a run starts, completed when it ends.
 */
final class RunRepository
{
    public const RUNNING = 'running';
    public const COMPLETED = 'completed';
    public const FAILED = 'failed';

    private const TABLE = 'runs';

    public function __construct(private readonly Database $db)
    {
    }

    /**
     * Opens a run in the "running" state.
     *
     * @param string   $kind           what kind of run it is ("full", "light", "manual")
     * @param PropertyMode $mode       mode of the property when the run started
     * @param int|null $ruleSetVersion version of the rules the run uses
     *
     * @return int id of the run
     */
    public function start(string $propertyId, string $kind, PropertyMode $mode, ?int $ruleSetVersion, DateTimeImmutable $startedAt): int
    {
        return $this->db->insert(self::TABLE, [
            'property_code' => $propertyId,
            'kind' => DbValue::word($kind, 16),
            'mode' => $mode->value,
            'status' => self::RUNNING,
            'ruleset_version' => $ruleSetVersion,
            'started_at' => DbValue::instant($startedAt),
            'summary' => DbValue::json([]),
        ]);
    }

    /**
     * Closes a run.
     *
     * @param string               $status  self::COMPLETED or self::FAILED
     * @param array<string, mixed> $summary counts and facts of the run; no guest data
     * @param string|null          $error   why it failed, as a sentence safe to show
     *
     * @throws InvalidArgumentException on another status
     * @throws OutOfBoundsException     when the run does not exist
     */
    public function finish(int $runId, string $status, array $summary, DateTimeImmutable $finishedAt, ?string $error = null): void
    {
        if (!in_array($status, [self::COMPLETED, self::FAILED], true)) {
            throw new InvalidArgumentException('A run ends as completed or failed.');
        }
        if ($this->find($runId) === null) {
            throw new OutOfBoundsException(sprintf('Unknown run %d.', $runId));
        }

        $this->db->update(self::TABLE, [
            'status' => $status,
            'summary' => DbValue::json($summary),
            'error' => $error,
            'finished_at' => DbValue::instant($finishedAt),
        ], ['id' => $runId]);
    }

    /**
     * @return array{id: int, property_id: string, kind: string, mode: PropertyMode, status: string, ruleset_version: int|null, started_at: DateTimeImmutable, finished_at: DateTimeImmutable|null, summary: array<string, mixed>, error: string|null}|null
     */
    public function find(int $runId): ?array
    {
        $row = $this->db->selectRow(sprintf('SELECT * FROM %s WHERE id = ?', $this->db->table(self::TABLE)), [$runId]);

        return $row === null ? null : $this->hydrate($row);
    }

    /**
     * The most recent runs, newest first.
     *
     * @param string|null $propertyId only this property; null for every property
     *
     * @return list<array{id: int, property_id: string, kind: string, mode: PropertyMode, status: string, ruleset_version: int|null, started_at: DateTimeImmutable, finished_at: DateTimeImmutable|null, summary: array<string, mixed>, error: string|null}>
     */
    public function latest(?string $propertyId = null, int $limit = 20): array
    {
        $sql = sprintf('SELECT * FROM %s', $this->db->table(self::TABLE));
        $params = [];
        if ($propertyId !== null) {
            $sql .= ' WHERE property_code = ?';
            $params[] = $propertyId;
        }
        $params[] = max(1, $limit);

        return array_map($this->hydrate(...), $this->db->select($sql . ' ORDER BY started_at DESC, id DESC LIMIT ?', $params));
    }

    /**
     * @param array<string, string|null> $row
     *
     * @return array{id: int, property_id: string, kind: string, mode: PropertyMode, status: string, ruleset_version: int|null, started_at: DateTimeImmutable, finished_at: DateTimeImmutable|null, summary: array<string, mixed>, error: string|null}
     */
    private function hydrate(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'property_id' => (string) $row['property_code'],
            'kind' => (string) $row['kind'],
            'mode' => PropertyMode::from((string) $row['mode']),
            'status' => (string) $row['status'],
            'ruleset_version' => DbValue::nullableInt($row['ruleset_version']),
            'started_at' => DbValue::requireInstant($row['started_at']),
            'finished_at' => DbValue::toInstant($row['finished_at']),
            'summary' => DbValue::toArray($row['summary']),
            'error' => $row['error'],
        ];
    }
}
