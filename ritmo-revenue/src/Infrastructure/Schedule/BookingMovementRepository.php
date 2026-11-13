<?php

declare(strict_types=1);

namespace Ritmo\Infrastructure\Schedule;

use DateTimeImmutable;
use Ritmo\Domain\Model\BookingStatus;
use Ritmo\Domain\Operations\BookingVersion;
use Ritmo\Domain\Operations\MovementKind;
use Ritmo\Infrastructure\Db\Database;
use Ritmo\Infrastructure\Db\DbValue;

/**
 * The booking versions each property processed (table booking_movements). Ids, rooms, dates,
 * status, units, totals and PMS timestamps only: never guest data.
 */
final class BookingMovementRepository
{
    private const TABLE = 'booking_movements';

    private const CHUNK = 200;

    public function __construct(private readonly Database $db)
    {
    }

    /**
     * The version processed last of each booking.
     *
     * @param list<string> $pmsRefs
     *
     * @return array<string, BookingVersion> PMS reference => version
     */
    public function lastVersions(string $propertyId, string $pms, array $pmsRefs): array
    {
        $versions = [];
        foreach (array_chunk(array_values(array_unique($pmsRefs)), self::CHUNK) as $chunk) {
            $sql = sprintf(
                'SELECT * FROM %s WHERE property_code = ? AND pms = ? AND pms_ref IN (%s) ORDER BY detected_at, id',
                $this->db->table(self::TABLE),
                implode(', ', array_fill(0, count($chunk), '?'))
            );
            foreach ($this->db->select($sql, [$propertyId, $pms, ...$chunk]) as $row) {
                $versions[(string) $row['pms_ref']] = $this->hydrate($row);
            }
        }

        return $versions;
    }

    /**
     * Records processed versions; a version already recorded is left as it is.
     *
     * @param list<array{0: BookingVersion, 1: MovementKind}> $versions
     */
    public function record(string $propertyId, string $pms, array $versions, DateTimeImmutable $detectedAt, ?int $runId): void
    {
        $this->db->transaction(function () use ($propertyId, $pms, $versions, $detectedAt, $runId): void {
            foreach ($versions as [$version, $kind]) {
                $this->db->insertIgnore(self::TABLE, [
                    'property_code' => $propertyId,
                    'pms' => $pms,
                    'pms_ref' => DbValue::reference($version->pmsRef, 64),
                    'fingerprint' => $version->fingerprint(),
                    'kind' => $kind->value,
                    'room_code' => $version->roomCode,
                    'arrival' => DbValue::night($version->arrival),
                    'departure' => DbValue::night($version->departure),
                    'status' => $version->status->value,
                    'units' => $version->units,
                    'total' => $version->total,
                    'modified_at' => DbValue::nullableInstant($version->modifiedAt),
                    'cancelled_at' => DbValue::nullableInstant($version->cancelledAt),
                    'run_id' => $runId,
                    'detected_at' => DbValue::instant($detectedAt),
                ]);
            }
        });
    }

    /**
     * Forgets the versions of bookings that departed before $date.
     */
    public function purgeDepartedBefore(DateTimeImmutable $date): int
    {
        return $this->db->execute(sprintf('DELETE FROM %s WHERE departure < ?', $this->db->table(self::TABLE)), [DbValue::night($date)]);
    }

    /**
     * @param array<string, string|null> $row
     */
    private function hydrate(array $row): BookingVersion
    {
        return new BookingVersion(
            (string) $row['pms_ref'],
            $row['room_code'],
            DbValue::requireNight($row['arrival']),
            DbValue::requireNight($row['departure']),
            BookingStatus::from((string) $row['status']),
            (int) $row['units'],
            DbValue::nullableFloat($row['total']),
            DbValue::toInstant($row['modified_at']),
            DbValue::toInstant($row['cancelled_at']),
        );
    }
}
