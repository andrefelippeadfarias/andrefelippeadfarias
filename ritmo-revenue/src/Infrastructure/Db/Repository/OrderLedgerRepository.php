<?php

declare(strict_types=1);

namespace Ritmo\Infrastructure\Db\Repository;

use DateTimeImmutable;
use Ritmo\Domain\Support\Days;
use Ritmo\Infrastructure\Db\Database;
use Ritmo\Infrastructure\Db\DatabaseException;
use Ritmo\Infrastructure\Db\DbValue;

/**
 * The owner orders of a property as rows to list (in force, ended), and the one change an order
 * may have after it is written: being ended. Orders are never edited nor deleted (a copy is a
 * new order), so their history stays readable.
 */
final class OrderLedgerRepository
{
    public const ENDED = 'ended';

    private const TABLE = 'orders';

    public function __construct(private readonly Database $db)
    {
    }

    /**
     * The active orders that may still cover a night from $from on, oldest first.
     *
     * @return list<array{id: int, kind: string, params: array<string, mixed>, status: string, created_by: int, created_at: DateTimeImmutable, updated_at: DateTimeImmutable, first_night: DateTimeImmutable|null, last_night: DateTimeImmutable|null}>
     */
    public function active(string $propertyId, DateTimeImmutable $from): array
    {
        return $this->rows(
            'property_code = ? AND status = ? AND (last_night IS NULL OR last_night >= ?) ORDER BY id',
            [$propertyId, OrderRepository::ACTIVE, DbValue::night(Days::normalize($from))]
        );
    }

    /**
     * Orders ended, or past their last night, since a date, newest first.
     *
     * @return list<array{id: int, kind: string, params: array<string, mixed>, status: string, created_by: int, created_at: DateTimeImmutable, updated_at: DateTimeImmutable, first_night: DateTimeImmutable|null, last_night: DateTimeImmutable|null}>
     */
    public function ended(string $propertyId, DateTimeImmutable $today, DateTimeImmutable $since): array
    {
        return $this->rows(
            'property_code = ? AND (status <> ? OR last_night < ?) AND updated_at >= ? ORDER BY updated_at DESC, id DESC LIMIT 200',
            [$propertyId, OrderRepository::ACTIVE, DbValue::night(Days::normalize($today)), DbValue::instant($since)]
        );
    }

    /**
     * @return array{id: int, kind: string, params: array<string, mixed>, status: string, created_by: int, created_at: DateTimeImmutable, updated_at: DateTimeImmutable, first_night: DateTimeImmutable|null, last_night: DateTimeImmutable|null}|null
     */
    public function find(string $propertyId, int $id): ?array
    {
        return $this->rows('property_code = ? AND id = ?', [$propertyId, $id])[0] ?? null;
    }

    /**
     * Ends an active order now. False when it was not active (already ended, or unknown).
     */
    public function end(string $propertyId, int $id, DateTimeImmutable $at): bool
    {
        return $this->db->update(
            self::TABLE,
            ['status' => self::ENDED, 'updated_at' => DbValue::instant($at), 'expires_at' => DbValue::instant($at)],
            ['id' => $id, 'property_code' => $propertyId, 'status' => OrderRepository::ACTIVE]
        ) > 0;
    }

    /**
     * @param list<int|string> $params
     *
     * @return list<array{id: int, kind: string, params: array<string, mixed>, status: string, created_by: int, created_at: DateTimeImmutable, updated_at: DateTimeImmutable, first_night: DateTimeImmutable|null, last_night: DateTimeImmutable|null}>
     */
    private function rows(string $where, array $params): array
    {
        return array_map(static function (array $row): array {
            try {
                $params = DbValue::toArray($row['params']);
            } catch (DatabaseException) {
                $params = [];
            }

            return [
                'id' => (int) $row['id'],
                'kind' => (string) $row['kind'],
                'params' => $params,
                'status' => (string) $row['status'],
                'created_by' => (int) $row['created_by'],
                'created_at' => DbValue::requireInstant($row['created_at']),
                'updated_at' => DbValue::requireInstant($row['updated_at']),
                'first_night' => DbValue::toNight($row['first_night']),
                'last_night' => DbValue::toNight($row['last_night']),
            ];
        }, $this->db->select(sprintf('SELECT * FROM %s WHERE %s', $this->db->table(self::TABLE), $where), $params));
    }
}
