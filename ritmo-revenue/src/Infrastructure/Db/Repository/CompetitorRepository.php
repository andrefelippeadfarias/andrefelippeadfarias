<?php

declare(strict_types=1);

namespace Ritmo\Infrastructure\Db\Repository;

use DateTimeImmutable;
use Ritmo\Domain\Market\Series\CompetitorProfile;
use Ritmo\Domain\Support\Clock;
use Ritmo\Infrastructure\Db\Database;
use Ritmo\Infrastructure\Db\DbValue;

/**
 * The competitors of each property (businesses the property compares itself with; never guests).
 * A competitor is never deleted: archiving makes it inactive and its prices stay for history.
 */
final class CompetitorRepository
{
    private const TABLE = 'competitors';

    public function __construct(private readonly Database $db, private readonly Clock $clock)
    {
    }

    /**
     * @param array{code: string, name: string, reference: string, room_label: string, tags: list<string>, factor: float|null, factor_source: string, rating: float|null, is_own: bool, active: bool} $fields
     *
     * @return int the new id
     */
    public function add(string $propertyId, array $fields): int
    {
        $now = DbValue::instant($this->clock->now());

        return $this->db->insert(self::TABLE, ['property_code' => $propertyId, 'created_at' => $now] + self::columns($fields, $now));
    }

    /**
     * @param array{code: string, name: string, reference: string, room_label: string, tags: list<string>, factor: float|null, factor_source: string, rating: float|null, is_own: bool, active: bool} $fields
     */
    public function update(string $propertyId, int $id, array $fields): bool
    {
        return $this->db->update(self::TABLE, self::columns($fields, DbValue::instant($this->clock->now())), ['id' => $id, 'property_code' => $propertyId]) > 0;
    }

    public function archive(string $propertyId, int $id): bool
    {
        return $this->db->update(self::TABLE, ['active' => false, 'updated_at' => DbValue::instant($this->clock->now())], ['id' => $id, 'property_code' => $propertyId]) > 0;
    }

    /**
     * @return array{id: int, code: string, name: string, reference: string, room_label: string, tags: list<string>, factor: float|null, factor_source: string, rating: float|null, is_own: bool, active: bool, updated_at: DateTimeImmutable}|null
     */
    public function find(string $propertyId, int $id): ?array
    {
        $row = $this->db->selectRow(sprintf('SELECT * FROM %s WHERE id = ? AND property_code = ?', $this->db->table(self::TABLE)), [$id, $propertyId]);

        return $row === null ? null : self::hydrate($row);
    }

    /**
     * The competitors of a property, by name.
     *
     * @return list<array{id: int, code: string, name: string, reference: string, room_label: string, tags: list<string>, factor: float|null, factor_source: string, rating: float|null, is_own: bool, active: bool, updated_at: DateTimeImmutable}>
     */
    public function forProperty(string $propertyId, bool $activeOnly = false): array
    {
        $sql = sprintf('SELECT * FROM %s WHERE property_code = ?', $this->db->table(self::TABLE)) . ($activeOnly ? ' AND active = 1' : '');

        return array_map(self::hydrate(...), $this->db->select($sql . ' ORDER BY name, id', [$propertyId]));
    }

    /**
     * What the market series need of each competitor, by id.
     *
     * @return array<int, CompetitorProfile>
     */
    public function profiles(string $propertyId): array
    {
        $profiles = [];
        foreach ($this->forProperty($propertyId) as $row) {
            $profiles[$row['id']] = new CompetitorProfile($row['id'], $row['code'], $row['tags'], $row['factor'], $row['factor_source'], $row['active'], $row['is_own']);
        }

        return $profiles;
    }

    /**
     * True when another competitor of the property already has the code.
     */
    public function codeTaken(string $propertyId, string $code, int $exceptId = 0): bool
    {
        return $this->db->selectValue(
            sprintf('SELECT COUNT(*) FROM %s WHERE property_code = ? AND LOWER(code) = ? AND id <> ?', $this->db->table(self::TABLE)),
            [$propertyId, strtolower($code), $exceptId]
        ) !== '0';
    }

    /**
     * The newest change of any competitor of the property (an input of the series cache).
     */
    public function lastChange(string $propertyId): ?DateTimeImmutable
    {
        return DbValue::toInstant($this->db->selectValue(sprintf('SELECT MAX(updated_at) FROM %s WHERE property_code = ?', $this->db->table(self::TABLE)), [$propertyId]));
    }

    /**
     * @param array<string, mixed> $fields
     *
     * @return array<string, mixed>
     */
    private static function columns(array $fields, string $now): array
    {
        return [
            'code' => DbValue::clip((string) $fields['code'], 64),
            'name' => DbValue::clip((string) $fields['name'], 190),
            'external_ref' => DbValue::clip((string) $fields['reference'], 190),
            'room_label' => DbValue::clip((string) $fields['room_label'], 190),
            'tags' => DbValue::json(array_values($fields['tags'])),
            'comparable_factor' => $fields['factor'],
            'factor_source' => (string) $fields['factor_source'],
            'rating' => $fields['rating'],
            'is_own' => (bool) $fields['is_own'],
            'active' => (bool) $fields['active'],
            'updated_at' => $now,
        ];
    }

    /**
     * @param array<string, string|null> $row
     *
     * @return array{id: int, code: string, name: string, reference: string, room_label: string, tags: list<string>, factor: float|null, factor_source: string, rating: float|null, is_own: bool, active: bool, updated_at: DateTimeImmutable}
     */
    private static function hydrate(array $row): array
    {
        $tags = array_values(array_filter(DbValue::toArray($row['tags']), static fn (mixed $t): bool => is_string($t) && preg_match(CompetitorProfile::TAG_PATTERN, $t) === 1));
        $code = (string) ($row['code'] ?? '');

        return [
            'id' => (int) $row['id'],
            'code' => preg_match(CompetitorProfile::CODE_PATTERN, $code) === 1 ? $code : 'competitor-' . (int) $row['id'],
            'name' => (string) $row['name'],
            'reference' => (string) $row['external_ref'],
            'room_label' => (string) $row['room_label'],
            'tags' => $tags,
            'factor' => DbValue::nullableFloat($row['comparable_factor'] ?? null),
            'factor_source' => in_array($row['factor_source'] ?? '', ['measured', 'estimated'], true) ? (string) $row['factor_source'] : '',
            'rating' => DbValue::nullableFloat($row['rating'] ?? null),
            'is_own' => (bool) (int) ($row['is_own'] ?? 0),
            'active' => (bool) (int) $row['active'],
            'updated_at' => DbValue::requireInstant($row['updated_at']),
        ];
    }
}
