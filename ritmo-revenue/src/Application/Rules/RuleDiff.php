<?php

declare(strict_types=1);

namespace Ritmo\Application\Rules;

use Ritmo\Domain\Config\FieldType;
use Ritmo\Domain\Config\Schema;

/**
 * What changed between two rule sets, one row per leaf value (spec 05, 5.5.1).
 *
 * Groups and maps are walked key by key. List items are matched by their "id" when both sides
 * have one, else by position; an item added or removed is one change. A list of plain values
 * (weekdays, room codes) is one change. With $listsAsUnits every list is one change as a whole:
 * the unit a three-way merge works on.
 */
final class RuleDiff
{
    private function __construct(private readonly Schema $schema, private readonly bool $listsAsUnits)
    {
    }

    /**
     * @param array<int|string, mixed> $from
     * @param array<int|string, mixed> $to
     *
     * @return list<FieldChange> in schema order
     */
    public static function between(array $from, array $to, Schema $schema, bool $listsAsUnits = false): array
    {
        return (new self($schema, $listsAsUnits))->walk('', '', $from, $to);
    }

    /**
     * @param string|null $key the schema key of the value ("" for the whole rule set), null outside the schema
     *
     * @return list<FieldChange>
     */
    private function walk(string $path, ?string $key, mixed $from, mixed $to): array
    {
        if (self::same($from, $to)) {
            return [];
        }
        $type = $key === '' ? FieldType::Group : ($key !== null && $this->schema->has($key) ? $this->schema->field($key)->type : null);
        if ($type === FieldType::ListOf) {
            return $this->listChanges($path, (string) $key, $from, $to);
        }
        $walkable = is_array($from) && is_array($to) && ($type === FieldType::Group || $type === FieldType::Map
            || (($type === null || $type === FieldType::Variant) && !(array_is_list($from) && array_is_list($to))));
        if (!$walkable) {
            return [new FieldChange($path, $key, FieldChange::CHANGED, $from, $to)];
        }
        $changes = [];
        foreach ($this->keysInOrder((string) $key, $type, $from, $to) as $name) {
            $childKey = match ($type) {
                FieldType::Group => $key === '' ? (string) $name : $key . '.' . $name,
                FieldType::Map => $key . '.' . Schema::WILDCARD,
                default => null,
            };
            $childKey = $childKey !== null && $this->schema->has($childKey) ? $childKey : null;
            $childPath = $path === '' ? (string) $name : $path . '.' . $name;
            if (!array_key_exists($name, $from)) {
                $changes[] = new FieldChange($childPath, $childKey, FieldChange::ADDED, null, $to[$name]);
            } elseif (!array_key_exists($name, $to)) {
                $changes[] = new FieldChange($childPath, $childKey, FieldChange::REMOVED, $from[$name], null);
            } else {
                $changes = [...$changes, ...$this->walk($childPath, $childKey, $from[$name], $to[$name])];
            }
        }

        return $changes;
    }

    /**
     * @return list<FieldChange>
     */
    private function listChanges(string $path, string $key, mixed $from, mixed $to): array
    {
        $itemKey = $key . '.' . Schema::WILDCARD;
        $itemType = $this->schema->has($itemKey) ? $this->schema->field($itemKey)->type : null;
        if ($this->listsAsUnits || !is_array($from) || !is_array($to) || $itemType !== FieldType::Group) {
            return [new FieldChange($path, $key, FieldChange::CHANGED, $from, $to)];
        }
        $byId = self::allHaveIds($from) && self::allHaveIds($to);
        $fromItems = $byId ? array_column($from, null, 'id') : $from;
        $toItems = $byId ? array_column($to, null, 'id') : $to;
        $positions = $byId ? array_flip(array_column($to, 'id')) : [];
        $oldPositions = $byId ? array_flip(array_column($from, 'id')) : [];
        $changes = [];
        foreach ($toItems as $id => $item) {
            $itemPath = $path . '.' . ($byId ? $positions[$id] : $id);
            $changes = isset($fromItems[$id])
                ? [...$changes, ...$this->walk($itemPath, $itemKey, $fromItems[$id], $item)]
                : [...$changes, new FieldChange($itemPath, $itemKey, FieldChange::ADDED, null, $item)];
        }
        foreach ($fromItems as $id => $item) {
            if (!isset($toItems[$id])) {
                $changes[] = new FieldChange($path . '.' . ($byId ? $oldPositions[$id] : $id), $itemKey, FieldChange::REMOVED, $item, null);
            }
        }

        return $changes;
    }

    /**
     * Children in schema order, then keys the schema does not name; map entries and unknown keys
     * in the order they appear.
     *
     * @param array<int|string, mixed> $from
     * @param array<int|string, mixed> $to
     *
     * @return list<int|string>
     */
    private function keysInOrder(string $key, ?FieldType $type, array $from, array $to): array
    {
        $present = array_values(array_unique([...array_keys($from), ...array_keys($to)]));
        if ($type !== FieldType::Group) {
            return $present;
        }
        $ordered = array_values(array_filter(array_keys($this->schema->children($key)), static fn (int|string $n): bool => in_array($n, $present, true)));

        return [...$ordered, ...array_values(array_diff($present, $ordered))];
    }

    /**
     * @param array<int|string, mixed> $items
     */
    private static function allHaveIds(array $items): bool
    {
        $ids = [];
        foreach ($items as $item) {
            if (!is_array($item) || !is_string($item['id'] ?? null) || isset($ids[$item['id']])) {
                return false;
            }
            $ids[$item['id']] = true;
        }

        return true;
    }

    /**
     * Equal values; whole numbers and floats with the same value are equal (70 and 70.0).
     */
    public static function same(mixed $a, mixed $b): bool
    {
        if ((is_int($a) || is_float($a)) && (is_int($b) || is_float($b))) {
            return (float) $a === (float) $b;
        }
        if (is_array($a) && is_array($b)) {
            if (count($a) !== count($b) || array_keys($a) !== array_keys($b)) {
                return false;
            }
            foreach ($a as $k => $v) {
                if (!self::same($v, $b[$k])) {
                    return false;
                }
            }

            return true;
        }

        return $a === $b;
    }
}
