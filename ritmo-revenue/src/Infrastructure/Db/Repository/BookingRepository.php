<?php

declare(strict_types=1);

namespace Ritmo\Infrastructure\Db\Repository;

use DateTimeImmutable;
use Ritmo\Domain\Model\BookingRecord;
use Ritmo\Domain\Model\BookingStatus;
use Ritmo\Infrastructure\Db\Database;
use Ritmo\Infrastructure\Db\DbValue;

/**
 * The site's copy of the bookings read from each PMS, one row per booking of a PMS.
 *
 * It stores exactly what BookingRecord carries: stay, room, status, channel, total and
 * timestamps. Nothing that identifies a guest is ever written here.
 */
final class BookingRepository
{
    private const TABLE = 'bookings';

    public function __construct(private readonly Database $db)
    {
    }

    /**
     * Stores the booking, replacing the row with the same PMS reference.
     *
     * @param string            $pms      id of the PMS connector the booking came from
     * @param DateTimeImmutable $syncedAt instant the booking was read from the PMS
     */
    public function upsert(string $pms, BookingRecord $booking, DateTimeImmutable $syncedAt): void
    {
        $this->db->upsert(self::TABLE, [
            'pms' => DbValue::word($pms, 32),
            'pms_ref' => DbValue::reference($booking->pmsRef, 64),
            'room_code' => $booking->roomCode,
            'room_pms_ref' => DbValue::reference($booking->roomPmsRef, 64),
            'arrival' => DbValue::night($booking->arrival),
            'departure' => DbValue::night($booking->departure),
            'status' => $booking->status->value,
            'channel' => DbValue::clip($booking->channel, 64),
            'origin' => DbValue::clip($booking->origin, 64),
            'total' => $booking->total,
            'units' => $booking->units,
            'booked_at' => DbValue::nullableInstant($booking->createdAt),
            'modified_at' => DbValue::nullableInstant($booking->modifiedAt),
            'cancelled_at' => DbValue::nullableInstant($booking->cancelledAt),
            'synced_at' => DbValue::instant($syncedAt),
        ], ['pms', 'pms_ref']);
    }

    /**
     * Stores every booking of one read, all or none.
     *
     * @param list<BookingRecord> $bookings
     *
     * @return int how many were stored
     */
    public function upsertMany(string $pms, array $bookings, DateTimeImmutable $syncedAt): int
    {
        return $this->db->transaction(function () use ($pms, $bookings, $syncedAt): int {
            foreach ($bookings as $booking) {
                $this->upsert($pms, $booking, $syncedAt);
            }

            return count($bookings);
        });
    }

    public function find(string $pms, string $pmsRef): ?BookingRecord
    {
        $row = $this->db->selectRow(
            sprintf('SELECT * FROM %s WHERE pms = ? AND pms_ref = ?', $this->db->table(self::TABLE)),
            [$pms, $pmsRef]
        );

        return $row === null ? null : $this->hydrate($row);
    }

    /**
     * Bookings of every status with at least one night in the range, by arrival.
     *
     * @param DateTimeImmutable $from     first night, inclusive
     * @param DateTimeImmutable $to       last night, inclusive
     * @param string|null       $roomCode only this room type; null for all, including unmapped rooms
     *
     * @return list<BookingRecord>
     */
    public function overlapping(DateTimeImmutable $from, DateTimeImmutable $to, ?string $roomCode = null): array
    {
        $sql = sprintf('SELECT * FROM %s WHERE arrival <= ? AND departure > ?', $this->db->table(self::TABLE));
        $params = [DbValue::night($to), DbValue::night($from)];
        if ($roomCode !== null) {
            $sql .= ' AND room_code = ?';
            $params[] = $roomCode;
        }

        return array_map($this->hydrate(...), $this->db->select($sql . ' ORDER BY arrival, pms, pms_ref', $params));
    }

    /**
     * The newest modification instant stored for a PMS: where the next incremental read
     * starts. Null when nothing with a modification time was stored yet.
     */
    public function latestModification(string $pms): ?DateTimeImmutable
    {
        return DbValue::toInstant($this->db->selectValue(
            sprintf('SELECT MAX(modified_at) FROM %s WHERE pms = ?', $this->db->table(self::TABLE)),
            [$pms]
        ));
    }

    /**
     * Removes the bookings that departed before a date (data retention).
     *
     * @return int rows removed
     */
    public function purgeDepartedBefore(DateTimeImmutable $date): int
    {
        return $this->db->execute(
            sprintf('DELETE FROM %s WHERE departure < ?', $this->db->table(self::TABLE)),
            [DbValue::night($date)]
        );
    }

    /**
     * @param array<string, string|null> $row
     */
    private function hydrate(array $row): BookingRecord
    {
        return new BookingRecord(
            (string) $row['pms_ref'],
            $row['room_code'],
            DbValue::requireNight($row['arrival']),
            DbValue::requireNight($row['departure']),
            BookingStatus::from((string) $row['status']),
            (string) $row['channel'],
            (string) $row['origin'],
            DbValue::nullableFloat($row['total']),
            DbValue::toInstant($row['booked_at']),
            DbValue::toInstant($row['modified_at']),
            DbValue::toInstant($row['cancelled_at']),
            (string) $row['room_pms_ref'],
            (int) $row['units']
        );
    }
}
