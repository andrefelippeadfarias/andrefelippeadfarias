<?php

declare(strict_types=1);

namespace Ritmo\Infrastructure\Db\Repository;

use Ritmo\Domain\Model\RoomType;
use Ritmo\Domain\Support\Clock;
use Ritmo\Infrastructure\Db\Database;
use Ritmo\Infrastructure\Db\DbValue;

/**
 * The room types of the site, each linked to its property by the property's id.
 */
final class RoomTypeRepository
{
    private const TABLE = 'room_types';

    public function __construct(private readonly Database $db, private readonly Clock $clock)
    {
    }

    /**
     * Creates the room type or replaces the one with the same code. A new room type goes to
     * the end of its property's list.
     */
    public function save(RoomType $room): void
    {
        $this->db->transaction(function () use ($room): void {
            $now = DbValue::instant($this->clock->now());
            $row = [
                'property_code' => $room->propertyId,
                'code' => $room->code,
                'name' => DbValue::clip($room->name, 190),
                'units' => $room->units,
                'pms_ref' => DbValue::reference($room->pmsRef, 64),
                'pricing_ref' => DbValue::reference($room->pricingRef, 64),
                'base_price' => $room->base,
                'min_price' => $room->min,
                'max_price' => $room->max,
                'reference_min' => $room->referenceMin,
                'tags' => DbValue::json(array_values($room->tags)),
                'updated_at' => $now,
            ];

            $id = CodeLookup::id($this->db, self::TABLE, $room->code);
            if ($id !== null) {
                $this->db->update(self::TABLE, $row, ['id' => $id]);

                return;
            }

            $last = $this->db->selectValue(
                sprintf('SELECT MAX(position) FROM %s WHERE property_code = ?', $this->db->table(self::TABLE)),
                [$room->propertyId]
            );
            $this->db->insert(self::TABLE, $row + ['position' => $last === null ? 0 : (int) $last + 1, 'created_at' => $now]);
        });
    }

    public function find(string $code): ?RoomType
    {
        $row = $this->row($code);

        return $row === null ? null : $this->hydrate($row);
    }

    /**
     * @return list<RoomType> in the order of the property's list
     */
    public function forProperty(string $propertyId): array
    {
        $rows = $this->db->select(
            sprintf('SELECT * FROM %s WHERE property_code = ? ORDER BY position, id', $this->db->table(self::TABLE)),
            [$propertyId]
        );

        return array_values(array_map(
            $this->hydrate(...),
            array_filter($rows, static fn (array $row): bool => $row['property_code'] === $propertyId)
        ));
    }

    /**
     * @return list<RoomType> property by property, in the order of each list
     */
    public function all(): array
    {
        return array_map(
            $this->hydrate(...),
            $this->db->select(sprintf('SELECT * FROM %s ORDER BY property_code, position, id', $this->db->table(self::TABLE)))
        );
    }

    /**
     * The room map a PMS connector is built with.
     *
     * @return array<string, string> PMS room id => room type code, for the mapped room types
     */
    public function pmsMap(): array
    {
        $map = [];
        foreach ($this->all() as $room) {
            if ($room->pmsRef !== '') {
                $map[$room->pmsRef] = $room->code;
            }
        }

        return $map;
    }

    public function delete(string $code): void
    {
        $row = $this->row($code);
        if ($row !== null) {
            $this->db->delete(self::TABLE, ['id' => (int) $row['id']]);
        }
    }

    /**
     * The stored row of exactly this code (the comparison of the database may ignore case).
     *
     * @return array<string, string|null>|null
     */
    private function row(string $code): ?array
    {
        $row = $this->db->selectRow(sprintf('SELECT * FROM %s WHERE code = ?', $this->db->table(self::TABLE)), [$code]);

        return $row !== null && $row['code'] === $code ? $row : null;
    }

    /**
     * @param array<string, string|null> $row
     */
    private function hydrate(array $row): RoomType
    {
        return new RoomType(
            (string) $row['property_code'],
            (string) $row['code'],
            (string) $row['name'],
            (int) $row['units'],
            (string) $row['pms_ref'],
            (string) $row['pricing_ref'],
            (float) $row['base_price'],
            (float) $row['min_price'],
            (float) $row['max_price'],
            (float) $row['reference_min'],
            array_map(strval(...), array_values(DbValue::toArray($row['tags'])))
        );
    }
}
