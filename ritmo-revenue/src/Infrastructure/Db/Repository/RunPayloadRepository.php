<?php

declare(strict_types=1);

namespace Ritmo\Infrastructure\Db\Repository;

use InvalidArgumentException;
use Ritmo\Domain\Support\Clock;
use Ritmo\Infrastructure\Db\Database;
use Ritmo\Infrastructure\Db\DbValue;

/**
 * The payload of a run's validated plan and its slices, as plain arrays (Payload::toArray(),
 * one row per slice in writing order). Kept for the person to inspect and for the writer of a
 * later milestone; nothing here sends anything.
 */
final class RunPayloadRepository
{
    public const PAYLOAD = 'payload';
    public const SLICE = 'slice';

    private const TABLE = 'run_payloads';

    public function __construct(private readonly Database $db, private readonly Clock $clock)
    {
    }

    /**
     * Stores the payload and the slices of a run, all or none.
     *
     * @param array<string, mixed>       $payload
     * @param list<array<string, mixed>> $slices in writing order
     */
    public function store(int $runId, string $propertyId, array $payload, array $slices): void
    {
        $this->db->transaction(function () use ($runId, $propertyId, $payload, $slices): void {
            $this->insert($runId, $propertyId, self::PAYLOAD, 0, $payload);
            foreach (array_values($slices) as $position => $slice) {
                $this->insert($runId, $propertyId, self::SLICE, $position + 1, $slice);
            }
        });
    }

    /**
     * @return array{payload: array<string, mixed>|null, slices: list<array<string, mixed>>}
     */
    public function forRun(int $runId): array
    {
        $rows = $this->db->select(
            sprintf('SELECT kind, body FROM %s WHERE run_id = ? ORDER BY kind, position, id', $this->db->table(self::TABLE)),
            [$runId]
        );
        $out = ['payload' => null, 'slices' => []];
        foreach ($rows as $row) {
            if ($row['kind'] === self::PAYLOAD) {
                $out['payload'] = DbValue::toArray($row['body']);
            } else {
                $out['slices'][] = DbValue::toArray($row['body']);
            }
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $body
     */
    private function insert(int $runId, string $propertyId, string $kind, int $position, array $body): void
    {
        if ($runId <= 0) {
            throw new InvalidArgumentException('A payload belongs to a stored run.');
        }
        $this->db->insert(self::TABLE, [
            'run_id' => $runId,
            'property_code' => $propertyId,
            'kind' => $kind,
            'position' => $position,
            'body' => DbValue::json($body),
            'created_at' => DbValue::instant($this->clock->now()),
        ]);
    }
}
