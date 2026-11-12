<?php

declare(strict_types=1);

namespace Ritmo\Infrastructure\Db\Repository;

use DateTimeImmutable;
use Ritmo\Domain\Model\PricePlan;
use Ritmo\Domain\Support\Clock;
use Ritmo\Infrastructure\Db\Database;
use Ritmo\Infrastructure\Db\DbValue;

/**
 * What a run proposes for each room night: one row per changed field, with the value in
 * force, the proposed value and the reasons. In shadow mode this is the whole result of a run.
 */
final class PlanItemRepository
{
    private const TABLE = 'plan_items';

    public function __construct(private readonly Database $db, private readonly Clock $clock)
    {
    }

    /**
     * @param string               $field    what would change ("floor", "ceiling", "min_stay")
     * @param float|null           $current  value in force; null when unknown
     * @param float|null           $proposed value the run proposes; null to remove the value
     * @param string               $reason   reason code of the rule that decided
     * @param array<string, mixed> $details  the "why": measures and rules behind the proposal; no guest data
     * @param PricePlan            $plan     price plan the two values are in
     * @param string               $status   state of the item ("proposed", "discarded", ...)
     *
     * @return int id of the item
     */
    public function add(
        int $runId,
        string $propertyId,
        string $roomCode,
        DateTimeImmutable $night,
        string $field,
        ?float $current,
        ?float $proposed,
        string $reason = '',
        array $details = [],
        PricePlan $plan = PricePlan::Sent,
        string $status = 'proposed',
    ): int {
        return $this->db->insert(self::TABLE, [
            'run_id' => $runId,
            'property_code' => $propertyId,
            'room_code' => $roomCode,
            'night' => DbValue::night($night),
            'field' => DbValue::word($field, 32),
            'plan' => $plan->value,
            'current_value' => $current,
            'proposed_value' => $proposed,
            'reason' => $reason === '' ? '' : DbValue::word($reason, 64),
            'details' => DbValue::json($details),
            'status' => DbValue::word($status, 16),
            'created_at' => DbValue::instant($this->clock->now()),
        ]);
    }

    /**
     * Stores the whole plan of a run, all items or none. Each item is the arguments of add()
     * after the run id, by name: ['propertyId' => ..., 'roomCode' => ..., 'night' => ..., 'field' => ..., 'current' => ..., 'proposed' => ..., ...].
     *
     * @param iterable<array<string, mixed>> $items
     *
     * @return int items stored
     */
    public function addAll(int $runId, iterable $items): int
    {
        return $this->db->transaction(function () use ($runId, $items): int {
            $stored = 0;
            foreach ($items as $item) {
                $this->add($runId, ...$item);
                ++$stored;
            }

            return $stored;
        });
    }

    /**
     * The plan of a run, by room and night.
     *
     * @return list<array{id: int, run_id: int, property_id: string, room_code: string, night: DateTimeImmutable, field: string, plan: PricePlan, current: float|null, proposed: float|null, reason: string, details: array<string, mixed>, status: string}>
     */
    public function forRun(int $runId): array
    {
        return array_map($this->hydrate(...), $this->db->select(
            sprintf('SELECT * FROM %s WHERE run_id = ? ORDER BY room_code, night, id', $this->db->table(self::TABLE)),
            [$runId]
        ));
    }

    /**
     * What recent runs proposed for one room night, newest first.
     *
     * @return list<array{id: int, run_id: int, property_id: string, room_code: string, night: DateTimeImmutable, field: string, plan: PricePlan, current: float|null, proposed: float|null, reason: string, details: array<string, mixed>, status: string}>
     */
    public function forNight(string $roomCode, DateTimeImmutable $night, int $limit = 20): array
    {
        return array_map($this->hydrate(...), $this->db->select(
            sprintf('SELECT * FROM %s WHERE room_code = ? AND night = ? ORDER BY id DESC LIMIT ?', $this->db->table(self::TABLE)),
            [$roomCode, DbValue::night($night), max(1, $limit)]
        ));
    }

    /**
     * @param array<string, string|null> $row
     *
     * @return array{id: int, run_id: int, property_id: string, room_code: string, night: DateTimeImmutable, field: string, plan: PricePlan, current: float|null, proposed: float|null, reason: string, details: array<string, mixed>, status: string}
     */
    private function hydrate(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'run_id' => (int) $row['run_id'],
            'property_id' => (string) $row['property_code'],
            'room_code' => (string) $row['room_code'],
            'night' => DbValue::requireNight($row['night']),
            'field' => (string) $row['field'],
            'plan' => PricePlan::from((string) $row['plan']),
            'current' => DbValue::nullableFloat($row['current_value']),
            'proposed' => DbValue::nullableFloat($row['proposed_value']),
            'reason' => (string) $row['reason'],
            'details' => DbValue::toArray($row['details']),
            'status' => (string) $row['status'],
        ];
    }
}
