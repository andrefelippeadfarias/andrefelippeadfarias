<?php

declare(strict_types=1);

namespace Ritmo\Infrastructure\Db;

use InvalidArgumentException;

/**
 * Builds the row statements shared by every Database implementation, so both speak exactly
 * the same SQL. Every method returns [sql with "?" placeholders, parameters].
 */
final class Statement
{
    private const IDENTIFIER = '/^[A-Za-z_][A-Za-z0-9_]{0,63}$/';

    /**
     * Returns the name when it is a plain SQL identifier.
     *
     * @throws InvalidArgumentException otherwise: identifiers are never built from input
     */
    public static function identifier(string $name): string
    {
        if (preg_match(self::IDENTIFIER, $name) !== 1) {
            throw new InvalidArgumentException(sprintf('"%s" is not a valid SQL identifier.', $name));
        }

        return $name;
    }

    /**
     * Parameters as the drivers take them: booleans as 0/1 and floats as text that reads back
     * as the same float (drivers would otherwise cut them to their own precision).
     *
     * @param array<array-key, mixed> $params
     *
     * @return list<int|string|null>
     */
    public static function normalize(array $params): array
    {
        $normalized = [];
        foreach ($params as $value) {
            $normalized[] = match (true) {
                $value === null, is_int($value), is_string($value) => $value,
                is_bool($value) => (int) $value,
                is_float($value) => self::floatText($value),
                default => throw new InvalidArgumentException(sprintf('A %s cannot be a statement parameter.', get_debug_type($value))),
            };
        }

        return $normalized;
    }

    /**
     * Number of "?" placeholders of a statement.
     */
    public static function placeholders(string $sql): int
    {
        return substr_count($sql, '?');
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array{0: string, 1: list<mixed>}
     */
    public static function insert(string $table, array $row, string $verb = 'INSERT INTO'): array
    {
        $columns = self::columns($row);
        $sql = sprintf(
            '%s %s (%s) VALUES (%s)',
            $verb,
            self::identifier($table),
            implode(', ', $columns),
            implode(', ', array_fill(0, count($columns), '?'))
        );

        return [$sql, array_values($row)];
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array{0: string, 1: list<mixed>}
     */
    public static function insertIgnore(string $dialect, string $table, array $row): array
    {
        return self::insert($table, $row, $dialect === Database::SQLITE ? 'INSERT OR IGNORE INTO' : 'INSERT IGNORE INTO');
    }

    /**
     * @param array<string, mixed> $row
     * @param list<string>         $uniqueColumns
     *
     * @return array{0: string, 1: list<mixed>}
     */
    public static function upsert(string $dialect, string $table, array $row, array $uniqueColumns): array
    {
        [$sql, $params] = self::insert($table, $row);
        $columns = self::columns($row);
        if ($uniqueColumns === [] || array_diff($uniqueColumns, $columns) !== []) {
            throw new InvalidArgumentException('An upsert needs its unique columns, and the row must carry all of them.');
        }
        $changing = array_values(array_diff($columns, $uniqueColumns));

        if ($dialect === Database::SQLITE) {
            $action = $changing === []
                ? 'DO NOTHING'
                : 'DO UPDATE SET ' . implode(', ', array_map(static fn (string $column): string => "$column = excluded.$column", $changing));

            return [sprintf('%s ON CONFLICT (%s) %s', $sql, implode(', ', $uniqueColumns), $action), $params];
        }

        $assignments = $changing === []
            ? [sprintf('%1$s = %1$s', $uniqueColumns[0])]
            : array_map(static fn (string $column): string => "$column = VALUES($column)", $changing);

        return [$sql . ' ON DUPLICATE KEY UPDATE ' . implode(', ', $assignments), $params];
    }

    /**
     * @param array<string, mixed> $values
     * @param array<string, mixed> $where
     *
     * @return array{0: string, 1: list<mixed>}
     */
    public static function update(string $table, array $values, array $where): array
    {
        $assignments = array_map(static fn (string $column): string => "$column = ?", self::columns($values));
        [$condition, $conditionParams] = self::where($where);

        return [
            sprintf('UPDATE %s SET %s WHERE %s', self::identifier($table), implode(', ', $assignments), $condition),
            array_merge(array_values($values), $conditionParams),
        ];
    }

    /**
     * @param array<string, mixed> $where
     *
     * @return array{0: string, 1: list<mixed>}
     */
    public static function delete(string $table, array $where): array
    {
        [$condition, $params] = self::where($where);

        return [sprintf('DELETE FROM %s WHERE %s', self::identifier($table), $condition), $params];
    }

    /**
     * @param array<string, mixed> $where
     *
     * @return array{0: string, 1: list<mixed>}
     */
    private static function where(array $where): array
    {
        if ($where === []) {
            throw new InvalidArgumentException('A condition is required: updating or deleting every row is never intended.');
        }

        $parts = [];
        $params = [];
        foreach ($where as $column => $value) {
            $column = self::identifier((string) $column);
            if ($value === null) {
                $parts[] = "$column IS NULL";
                continue;
            }
            $parts[] = "$column = ?";
            $params[] = $value;
        }

        return [implode(' AND ', $parts), $params];
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return list<string>
     */
    private static function columns(array $row): array
    {
        if ($row === []) {
            throw new InvalidArgumentException('A row needs at least one column.');
        }

        return array_map(static fn (int|string $column): string => self::identifier((string) $column), array_keys($row));
    }

    /**
     * A float as the shortest text that reads back as exactly the same float.
     *
     * @throws InvalidArgumentException on NaN or infinity
     */
    public static function floatText(float $value): string
    {
        if (!is_finite($value)) {
            throw new InvalidArgumentException('NaN and infinity cannot be stored.');
        }

        $short = sprintf('%.15G', $value);

        return (float) $short === $value ? $short : sprintf('%.17G', $value);
    }
}
