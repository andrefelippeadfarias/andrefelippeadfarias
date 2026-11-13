<?php

declare(strict_types=1);

namespace Ritmo\Infrastructure\Db\Repository;

use DateTimeImmutable;
use Ritmo\Domain\Support\Days;
use Ritmo\Domain\Write\ThirdParty\DetectedFreeze;
use Ritmo\Infrastructure\Db\Database;
use Ritmo\Infrastructure\Db\DbValue;

/**
 * Nights frozen because someone else changed them (table detected_freezes). A freeze
 * ends when a person releases it, its time ends or its nights pass; rows are kept for the record.
 */
final class DetectedFreezeRepository
{
    private const TABLE = 'detected_freezes';

    public function __construct(private readonly Database $db)
    {
    }

    public function add(string $propertyId, DetectedFreeze $freeze, ?int $runId = null): int
    {
        return $this->db->insert(self::TABLE, [
            'property_code' => $propertyId,
            'room_code' => $freeze->roomCode,
            'nights' => DbValue::json(array_map([Days::class, 'key'], $freeze->nights)),
            'fields' => DbValue::json($freeze->fields),
            'detected_at' => DbValue::instant($freeze->detectedAt),
            'frozen_until' => DbValue::nullableInstant($freeze->frozenUntil),
            'run_id' => $runId,
        ]);
    }

    /**
     * The freezes of the property not released, newest last (the caller keeps the active nights).
     *
     * @return list<DetectedFreeze>
     */
    public function unreleased(string $propertyId): array
    {
        $rows = $this->db->select(
            sprintf('SELECT * FROM %s WHERE property_code = ? AND released_at IS NULL ORDER BY detected_at, id', $this->db->table(self::TABLE)),
            [$propertyId]
        );

        return array_map(static fn (array $row): DetectedFreeze => new DetectedFreeze(
            (string) $row['room_code'],
            array_map(static fn (mixed $n): DateTimeImmutable => Days::date((string) $n), DbValue::toArray($row['nights'])),
            DbValue::requireInstant($row['detected_at']),
            DbValue::toInstant($row['frozen_until']),
            array_map('strval', DbValue::toArray($row['fields'])),
            (int) $row['id'],
        ), $rows);
    }

    /**
     * A person releases the freeze.
     *
     * @return bool false when it was already released
     */
    public function release(int $id, int $userId, DateTimeImmutable $at): bool
    {
        return $this->db->execute(
            sprintf('UPDATE %s SET released_at = ?, released_by = ? WHERE id = ? AND released_at IS NULL', $this->db->table(self::TABLE)),
            [DbValue::instant($at), $userId, $id]
        ) === 1;
    }
}
