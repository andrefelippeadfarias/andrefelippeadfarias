<?php

declare(strict_types=1);

namespace Ritmo\Infrastructure\Schedule;

use DateTimeImmutable;
use Ritmo\Domain\Operations\OperationState;
use Ritmo\Domain\Support\Clock;
use Ritmo\Infrastructure\Db\Database;
use Ritmo\Infrastructure\Db\DbValue;

/**
 * The operation state of each property (table operation_state): pause and cursors. A property
 * without a row has the neutral state (not paused, no cursor).
 */
final class OperationStateRepository
{
    private const TABLE = 'operation_state';

    public function __construct(private readonly Database $db, private readonly Clock $clock)
    {
    }

    public function get(string $propertyId): OperationState
    {
        $row = $this->db->selectRow(sprintf('SELECT * FROM %s WHERE property_code = ?', $this->db->table(self::TABLE)), [$propertyId]);
        if ($row === null) {
            return new OperationState($propertyId);
        }

        return new OperationState(
            $propertyId,
            (bool) (int) $row['paused'],
            DbValue::toInstant($row['paused_until']),
            DbValue::nullableInt($row['paused_by']),
            (string) $row['pause_reason'],
            DbValue::toInstant($row['movement_cursor']),
            DbValue::toInstant($row['slots_considered_until']),
        );
    }

    public function pause(string $propertyId, ?DateTimeImmutable $until, int $userId, string $reason): void
    {
        $this->save($propertyId, [
            'paused' => 1,
            'paused_until' => DbValue::nullableInstant($until),
            'paused_by' => $userId,
            'pause_reason' => DbValue::clip($reason, 190),
        ]);
    }

    public function resume(string $propertyId): void
    {
        $this->save($propertyId, ['paused' => 0, 'paused_until' => null, 'paused_by' => null, 'pause_reason' => '']);
    }

    public function setMovementCursor(string $propertyId, DateTimeImmutable $cursor): void
    {
        $this->save($propertyId, ['movement_cursor' => DbValue::instant($cursor)]);
    }

    public function setSlotsConsideredUntil(string $propertyId, DateTimeImmutable $instant): void
    {
        $this->save($propertyId, ['slots_considered_until' => DbValue::instant($instant)]);
    }

    /**
     * @param array<string, int|string|null> $values
     */
    private function save(string $propertyId, array $values): void
    {
        $this->db->transaction(function () use ($propertyId, $values): void {
            $values['updated_at'] = DbValue::instant($this->clock->now());
            if (!$this->db->insertIgnore(self::TABLE, ['property_code' => $propertyId] + $values)) {
                $this->db->update(self::TABLE, $values, ['property_code' => $propertyId]);
            }
        });
    }
}
