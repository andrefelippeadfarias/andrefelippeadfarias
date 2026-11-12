<?php

declare(strict_types=1);

namespace Ritmo\Infrastructure\Db\Repository;

use DateTimeImmutable;
use Ritmo\Domain\Model\InventoryNight;
use Ritmo\Domain\Model\Restriction;
use Ritmo\Infrastructure\Db\Database;
use Ritmo\Infrastructure\Db\DbValue;

/**
 * What the PMS calendar said about each room night every time it was read (table
 * "snapshots"). Keeping every capture lets the engine compare a night with how it stood
 * earlier.
 */
final class InventorySnapshotRepository
{
    private const TABLE = 'snapshots';

    public function __construct(private readonly Database $db)
    {
    }

    /**
     * Stores one read of a room's calendar, all nights or none. Storing the same room and
     * instant again replaces that capture.
     *
     * @param list<InventoryNight> $nights
     * @param DateTimeImmutable    $capturedAt instant of the read
     *
     * @return int nights stored
     */
    public function store(string $roomCode, array $nights, DateTimeImmutable $capturedAt, ?int $runId = null): int
    {
        return $this->db->transaction(function () use ($roomCode, $nights, $capturedAt, $runId): int {
            foreach ($nights as $night) {
                $this->db->upsert(self::TABLE, [
                    'room_code' => $roomCode,
                    'night' => DbValue::night($night->date),
                    'captured_at' => DbValue::instant($capturedAt),
                    'run_id' => $runId,
                    'available' => $night->available,
                    'min_stay' => $night->minStay,
                    'max_stay' => $night->maxStay,
                    'restriction' => $night->restriction->value,
                    'pms_price' => $night->pmsPrice,
                ], ['room_code', 'night', 'captured_at']);
            }

            return count($nights);
        });
    }

    /**
     * The most recent capture of each night of a room in the range.
     *
     * @param DateTimeImmutable      $from first night, inclusive
     * @param DateTimeImmutable      $to   last night, inclusive
     * @param DateTimeImmutable|null $asOf only captures made at or before this instant: the calendar as it was known then
     *
     * @return array<string, InventoryNight> keyed by "YYYY-MM-DD", ascending; nights never captured are absent
     */
    public function latest(string $roomCode, DateTimeImmutable $from, DateTimeImmutable $to, ?DateTimeImmutable $asOf = null): array
    {
        $table = $this->db->table(self::TABLE);
        $newest = sprintf('SELECT MAX(c.captured_at) FROM %s c WHERE c.room_code = s.room_code AND c.night = s.night', $table);
        $params = [$roomCode, DbValue::night($from), DbValue::night($to)];
        if ($asOf !== null) {
            $newest .= ' AND c.captured_at <= ?';
            $params[] = DbValue::instant($asOf);
        }

        $rows = $this->db->select(
            sprintf('SELECT s.* FROM %s s WHERE s.room_code = ? AND s.night >= ? AND s.night <= ? AND s.captured_at = (%s) ORDER BY s.night', $table, $newest),
            $params
        );

        $nights = [];
        foreach ($rows as $row) {
            $night = DbValue::requireNight($row['night']);
            $nights[DbValue::night($night)] = new InventoryNight(
                $night,
                (int) $row['available'],
                DbValue::nullableInt($row['min_stay']),
                DbValue::nullableInt($row['max_stay']),
                Restriction::from((string) $row['restriction']),
                DbValue::nullableFloat($row['pms_price'])
            );
        }

        return $nights;
    }

    /**
     * Instant of the newest capture of a room; null when it was never captured.
     */
    public function lastCapturedAt(string $roomCode): ?DateTimeImmutable
    {
        return DbValue::toInstant($this->db->selectValue(
            sprintf('SELECT MAX(captured_at) FROM %s WHERE room_code = ?', $this->db->table(self::TABLE)),
            [$roomCode]
        ));
    }

    /**
     * Removes the captures made before an instant (data retention).
     *
     * @return int rows removed
     */
    public function purgeCapturedBefore(DateTimeImmutable $instant): int
    {
        return $this->db->execute(
            sprintf('DELETE FROM %s WHERE captured_at < ?', $this->db->table(self::TABLE)),
            [DbValue::instant($instant)]
        );
    }
}
