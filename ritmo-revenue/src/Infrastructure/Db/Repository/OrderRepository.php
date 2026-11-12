<?php

declare(strict_types=1);

namespace Ritmo\Infrastructure\Db\Repository;

use DateTimeImmutable;
use Ritmo\Domain\Orders\OrderBook;
use Ritmo\Domain\Orders\OrderReader;
use Ritmo\Domain\Support\Clock;
use Ritmo\Domain\Support\Days;
use Ritmo\Infrastructure\Db\Database;
use Ritmo\Infrastructure\Db\DbValue;

/**
 * The owner orders of a property. A row keeps the order's fields as the order reader takes them
 * (in "params"); the columns hold what lists and filters need: kind, first and last night,
 * status. An order that does not read fails the run that needs it (an order is never ignored).
 */
final class OrderRepository
{
    public const ACTIVE = 'active';

    private const TABLE = 'orders';

    public function __construct(private readonly Database $db, private readonly Clock $clock)
    {
    }

    /**
     * @param array<string, mixed> $order the order's fields (type, rooms, from, to, releases, ...); "id" is generated when missing
     *
     * @return int id of the row
     */
    public function add(string $propertyId, array $order, int $userId = 0): int
    {
        $read = (new OrderReader())->read($order + ['id' => 'order-new']);
        $now = DbValue::instant($this->clock->now());

        return $this->db->insert(self::TABLE, [
            'property_code' => $propertyId,
            'room_code' => '',
            'kind' => DbValue::word((string) $order['type'], 32),
            'first_night' => DbValue::night($read->from),
            'last_night' => DbValue::night($read->to),
            'params' => DbValue::json($order),
            'status' => self::ACTIVE,
            'created_by' => $userId,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    /**
     * The active orders of a property that may still cover a night from $from on.
     */
    public function book(string $propertyId, DateTimeImmutable $from): OrderBook
    {
        $rows = $this->db->select(
            sprintf('SELECT * FROM %s WHERE property_code = ? AND status = ? AND (last_night IS NULL OR last_night >= ?) ORDER BY id', $this->db->table(self::TABLE)),
            [$propertyId, self::ACTIVE, DbValue::night(Days::normalize($from))]
        );
        $reader = new OrderReader();

        return new OrderBook(array_map(
            static fn (array $row) => $reader->read(DbValue::toArray($row['params']) + ['id' => 'order-' . $row['id']]),
            $rows,
        ));
    }
}
