<?php

declare(strict_types=1);

namespace Ritmo\Infrastructure\Db\Repository;

use OutOfBoundsException;
use Ritmo\Domain\Model\Property;
use Ritmo\Domain\Model\PropertyMode;
use Ritmo\Domain\Support\Clock;
use Ritmo\Infrastructure\Db\Database;
use Ritmo\Infrastructure\Db\DbValue;

/**
 * The properties of the site. Property::$id is stored in the "code" column.
 */
final class PropertyRepository
{
    private const TABLE = 'properties';

    public function __construct(private readonly Database $db, private readonly Clock $clock)
    {
    }

    /**
     * Creates the property or replaces the one with the same id.
     */
    public function save(Property $property): void
    {
        $this->db->transaction(function () use ($property): void {
            $now = DbValue::instant($this->clock->now());
            $row = [
                'code' => $property->id,
                'name' => DbValue::clip($property->name, 190),
                'timezone' => $property->timezone,
                'currency' => $property->currency,
                'mode' => $property->mode->value,
                'tier' => $property->tier,
                'occupancy_goals' => DbValue::json($property->occupancyGoals),
                'updated_at' => $now,
            ];

            $id = CodeLookup::id($this->db, self::TABLE, $property->id);
            if ($id === null) {
                $this->db->insert(self::TABLE, $row + ['created_at' => $now]);
            } else {
                $this->db->update(self::TABLE, $row, ['id' => $id]);
            }
        });
    }

    public function find(string $id): ?Property
    {
        $row = $this->row($id);

        return $row === null ? null : $this->hydrate($row);
    }

    /**
     * @return list<Property> in the order they were created
     */
    public function all(): array
    {
        return array_map($this->hydrate(...), $this->db->select(sprintf('SELECT * FROM %s ORDER BY id', $this->db->table(self::TABLE))));
    }

    /**
     * Changes only the operating mode of a property.
     *
     * @throws OutOfBoundsException when the property does not exist
     */
    public function setMode(string $id, PropertyMode $mode): void
    {
        $row = $this->row($id);
        if ($row === null) {
            throw new OutOfBoundsException(sprintf('Unknown property "%s".', $id));
        }

        $this->db->update(self::TABLE, ['mode' => $mode->value, 'updated_at' => DbValue::instant($this->clock->now())], ['id' => (int) $row['id']]);
    }

    public function delete(string $id): void
    {
        $row = $this->row($id);
        if ($row !== null) {
            $this->db->delete(self::TABLE, ['id' => (int) $row['id']]);
        }
    }

    /**
     * The stored row of exactly this id (the comparison of the database may ignore case).
     *
     * @return array<string, string|null>|null
     */
    private function row(string $id): ?array
    {
        $row = $this->db->selectRow(sprintf('SELECT * FROM %s WHERE code = ?', $this->db->table(self::TABLE)), [$id]);

        return $row !== null && $row['code'] === $id ? $row : null;
    }

    /**
     * @param array<string, string|null> $row
     */
    private function hydrate(array $row): Property
    {
        $goals = [];
        foreach (DbValue::toArray($row['occupancy_goals']) as $window => $percent) {
            $goals[(int) $window] = (float) $percent;
        }

        return new Property(
            (string) $row['code'],
            (string) $row['name'],
            (string) $row['timezone'],
            (string) $row['currency'],
            PropertyMode::from((string) $row['mode']),
            $goals,
            (string) $row['tier']
        );
    }
}
