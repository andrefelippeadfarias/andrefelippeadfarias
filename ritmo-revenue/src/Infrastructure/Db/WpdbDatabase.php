<?php

declare(strict_types=1);

namespace Ritmo\Infrastructure\Db;

use InvalidArgumentException;

/**
 * Database over WordPress's $wpdb. Every statement with a value goes through $wpdb->prepare.
 *
 * It always speaks the MySQL dialect: when a site runs on SQLite, WordPress's SQLite
 * integration translates the statements.
 */
final class WpdbDatabase implements Database
{
    use NestedTransactions;

    private readonly string $prefix;

    /**
     * @param object $wpdb      the global $wpdb
     * @param string $namespace what follows the site prefix in every plugin table name
     */
    public function __construct(private readonly object $wpdb, string $namespace = 'ritmo_')
    {
        $this->prefix = Statement::identifier($wpdb->prefix . $namespace);
    }

    public function dialect(): string
    {
        return self::MYSQL;
    }

    public function prefix(): string
    {
        return $this->prefix;
    }

    public function table(string $name): string
    {
        return $this->prefix . Statement::identifier($name);
    }

    /**
     * The "DEFAULT CHARACTER SET ... COLLATE ..." clause new tables of this site must carry.
     */
    public function charsetCollate(): string
    {
        return (string) $this->wpdb->get_charset_collate();
    }

    public function select(string $sql, array $params = []): array
    {
        $query = $this->prepare($sql, $params);
        $rows = $this->guarded(fn (): mixed => $this->wpdb->get_results($query, 'ARRAY_A'));

        return is_array($rows) ? array_values($rows) : [];
    }

    public function selectRow(string $sql, array $params = []): ?array
    {
        return $this->select($sql, $params)[0] ?? null;
    }

    public function selectValue(string $sql, array $params = []): ?string
    {
        $row = $this->selectRow($sql, $params);
        if ($row === null) {
            return null;
        }
        $value = reset($row);

        return $value === null ? null : (string) $value;
    }

    public function execute(string $sql, array $params = []): int
    {
        $query = $this->prepare($sql, $params);
        $result = $this->guarded(fn (): mixed => $this->wpdb->query($query));

        return is_int($result) ? $result : 0;
    }

    public function insert(string $table, array $row): int
    {
        [$sql, $params] = Statement::insert($this->table($table), $row);
        $this->execute($sql, $params);

        return (int) $this->wpdb->insert_id;
    }

    public function insertIgnore(string $table, array $row): bool
    {
        [$sql, $params] = Statement::insertIgnore(self::MYSQL, $this->table($table), $row);

        return $this->execute($sql, $params) > 0;
    }

    public function upsert(string $table, array $row, array $uniqueColumns): void
    {
        [$sql, $params] = Statement::upsert(self::MYSQL, $this->table($table), $row, $uniqueColumns);
        $this->execute($sql, $params);
    }

    public function update(string $table, array $values, array $where): int
    {
        [$sql, $params] = Statement::update($this->table($table), $values, $where);

        return $this->execute($sql, $params);
    }

    public function delete(string $table, array $where): int
    {
        [$sql, $params] = Statement::delete($this->table($table), $where);

        return $this->execute($sql, $params);
    }

    /**
     * Turns "?" placeholders into the formats of $wpdb->prepare and prepares the statement.
     * NULL is written as a keyword, because prepare has no format for it.
     *
     * @param array<array-key, mixed> $params
     */
    private function prepare(string $sql, array $params): string
    {
        if (Statement::placeholders($sql) !== count($params)) {
            throw new InvalidArgumentException('The statement and its parameters do not match.');
        }
        if (str_contains($sql, '%')) {
            throw new InvalidArgumentException('A statement must not contain "%": pass the value as a parameter.');
        }

        $values = Statement::normalize($params);
        $parts = explode('?', $sql);
        $format = (string) array_shift($parts);
        $arguments = [];
        foreach ($parts as $index => $part) {
            $value = $values[$index];
            if ($value === null) {
                $format .= 'NULL';
            } else {
                $format .= is_int($value) ? '%d' : '%s';
                $arguments[] = $value;
            }
            $format .= $part;
        }

        return $arguments === [] ? $format : (string) $this->wpdb->prepare($format, ...$arguments);
    }

    /**
     * Runs one $wpdb call with its HTML error output off and turns a failure into an exception.
     *
     * While it runs, PHP writes floats in full: a database driver that turns numbers into
     * text inside PHP (WordPress on SQLite does) would otherwise cut them to the "precision"
     * setting, and a price would come back slightly different from what was stored.
     *
     * @param callable(): mixed $call
     */
    private function guarded(callable $call): mixed
    {
        $suppressed = $this->wpdb->suppress_errors(true);
        $precision = function_exists('ini_set') ? ini_set('precision', '-1') : false;
        try {
            $result = $call();
        } finally {
            $this->wpdb->suppress_errors($suppressed);
            if (is_string($precision)) {
                ini_set('precision', $precision);
            }
        }

        $error = (string) $this->wpdb->last_error;
        if ($result === false || $error !== '') {
            throw new DatabaseException('The database refused a statement: ' . ($error !== '' ? $error : 'unknown error'));
        }

        return $result;
    }

    private function beginTransaction(): void
    {
        $this->execute('START TRANSACTION');
    }

    private function commitTransaction(): void
    {
        $this->execute('COMMIT');
    }

    private function rollBackTransaction(): void
    {
        $this->execute('ROLLBACK');
    }
}
