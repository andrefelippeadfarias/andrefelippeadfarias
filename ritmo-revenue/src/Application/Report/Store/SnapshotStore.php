<?php

declare(strict_types=1);

namespace Ritmo\Application\Report\Store;

use DateTimeImmutable;
use InvalidArgumentException;
use Ritmo\Domain\Report\Review\ReviewSnapshot;
use Ritmo\Infrastructure\Db\Database;
use Ritmo\Infrastructure\Db\DbValue;

/**
 * Saved 12-month reviews (table report_snapshots): totals per month and property, never a booking.
 */
final class SnapshotStore
{
    public const TABLE = 'report_snapshots';
    public const REVIEW = 'review_12m';

    public function __construct(private readonly Database $db)
    {
    }

    /**
     * The newest review taken before $day (the one a review of $day compares with).
     */
    public function latestBefore(string $propertyId, DateTimeImmutable $day): ?ReviewSnapshot
    {
        $row = $this->db->selectRow(
            sprintf('SELECT data FROM %s WHERE property_code = ? AND kind = ? AND taken_on < ? ORDER BY taken_on DESC LIMIT 1', $this->db->table(self::TABLE)),
            [$propertyId, self::REVIEW, DbValue::night($day)]
        );

        return $row === null ? null : self::read((string) $row['data']);
    }

    /**
     * The day of the newest review (null: never taken).
     */
    public function lastTaken(string $propertyId): ?DateTimeImmutable
    {
        $day = $this->db->selectValue(
            sprintf('SELECT MAX(taken_on) FROM %s WHERE property_code = ? AND kind = ?', $this->db->table(self::TABLE)),
            [$propertyId, self::REVIEW]
        );

        return DbValue::toNight($day);
    }

    public function save(string $propertyId, ReviewSnapshot $snapshot, int $userId, DateTimeImmutable $now): void
    {
        $this->db->upsert(self::TABLE, [
            'property_code' => $propertyId,
            'kind' => self::REVIEW,
            'taken_on' => DbValue::night($snapshot->day),
            'data' => DbValue::json($snapshot->toArray()),
            'created_by' => $userId,
            'created_at' => DbValue::instant($now),
        ], ['property_code', 'kind', 'taken_on']);
    }

    private static function read(string $data): ?ReviewSnapshot
    {
        try {
            return ReviewSnapshot::fromArray(DbValue::toArray($data));
        } catch (InvalidArgumentException) {
            return null;
        }
    }
}
