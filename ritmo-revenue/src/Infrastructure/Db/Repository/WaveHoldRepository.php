<?php

declare(strict_types=1);

namespace Ritmo\Infrastructure\Db\Repository;

use DateTimeImmutable;
use Ritmo\Domain\Write\Hold\WaveHold;
use Ritmo\Infrastructure\Db\Database;
use Ritmo\Infrastructure\Db\DbValue;

/**
 * The cancellation-wave hold of each property (table wave_holds): one open row per
 * property until a person releases it; a newer wave widens the open row. Bookings appear by
 * their PMS id only.
 */
final class WaveHoldRepository
{
    private const TABLE = 'wave_holds';

    public function __construct(private readonly Database $db)
    {
    }

    /**
     * The open hold of the property, or null.
     */
    public function open(string $propertyId): ?WaveHold
    {
        $row = $this->db->selectRow(
            sprintf('SELECT * FROM %s WHERE property_code = ? AND released_at IS NULL ORDER BY id DESC LIMIT 1', $this->db->table(self::TABLE)),
            [$propertyId]
        );
        if ($row === null) {
            return null;
        }

        return new WaveHold(
            DbValue::requireInstant($row['since']),
            DbValue::requireInstant($row['last_cancellation_at']),
            array_map(static fn (mixed $v): bool => (bool) $v, DbValue::toArray($row['nights'])),
            null,
            (int) $row['id'],
        );
    }

    /**
     * Opens the hold, or widens the open one with the newer waves.
     *
     * @param list<string> $bookingRefs the PMS ids of the cancelled bookings
     *
     * @return int id of the open row
     */
    public function hold(string $propertyId, WaveHold $hold, array $bookingRefs, DateTimeImmutable $now): int
    {
        return $this->db->transaction(function () use ($propertyId, $hold, $bookingRefs, $now): int {
            $open = $this->open($propertyId);
            $merged = $open === null ? $hold : $open->with($hold);
            $refs = $open === null ? [] : $this->bookingRefs((int) $open->id);
            $row = [
                'since' => DbValue::instant($merged->since),
                'last_cancellation_at' => DbValue::instant($merged->lastCancellationAt),
                'nights' => DbValue::json($merged->nights),
                'booking_refs' => DbValue::json(array_values(array_unique([...$refs, ...$bookingRefs]))),
                'updated_at' => DbValue::instant($now),
            ];
            if ($open === null) {
                return $this->db->insert(self::TABLE, ['property_code' => $propertyId] + $row);
            }
            $this->db->update(self::TABLE, $row, ['id' => (int) $open->id]);

            return (int) $open->id;
        });
    }

    /**
     * A person confirmed the cancellations: the hold ends.
     *
     * @return bool false when no hold was open
     */
    public function release(string $propertyId, int $userId, DateTimeImmutable $at): bool
    {
        return $this->db->execute(
            sprintf('UPDATE %s SET released_at = ?, released_by = ?, updated_at = ? WHERE property_code = ? AND released_at IS NULL', $this->db->table(self::TABLE)),
            [DbValue::instant($at), $userId, DbValue::instant($at), $propertyId]
        ) > 0;
    }

    /**
     * @return list<string>
     */
    private function bookingRefs(int $id): array
    {
        $stored = $this->db->selectValue(sprintf('SELECT booking_refs FROM %s WHERE id = ?', $this->db->table(self::TABLE)), [$id]);

        return array_map('strval', DbValue::toArray($stored));
    }
}
