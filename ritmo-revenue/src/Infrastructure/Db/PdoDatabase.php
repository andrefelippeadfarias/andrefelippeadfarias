<?php

declare(strict_types=1);

namespace Ritmo\Infrastructure\Db;

use InvalidArgumentException;
use PDO;
use PDOException;
use PDOStatement;

/**
 * Database over PDO (SQLite or MySQL). Used by the tests, with SQLite in memory, and by
 * command-line tools that run without WordPress.
 */
final class PdoDatabase implements Database
{
    use NestedTransactions;

    private readonly string $dialect;

    private readonly string $prefix;

    /**
     * @param string $prefix prefix of the plugin tables, as WordPress would build it
     *
     * @throws InvalidArgumentException when the PDO driver is neither SQLite nor MySQL
     */
    public function __construct(private readonly PDO $pdo, string $prefix = 'wp_ritmo_')
    {
        $driver = (string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        if (!in_array($driver, [self::MYSQL, self::SQLITE], true)) {
            throw new InvalidArgumentException(sprintf('Unsupported PDO driver "%s".', $driver));
        }

        $this->dialect = $driver;
        $this->prefix = Statement::identifier($prefix);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        // Native values are fetched and turned into text here (see textRow), because PDO's own
        // conversion cuts floats to the "precision" setting.
        $pdo->setAttribute(PDO::ATTR_STRINGIFY_FETCHES, false);
    }

    /**
     * A private SQLite database that lives while the object does.
     */
    public static function inMemory(string $prefix = 'wp_ritmo_'): self
    {
        return new self(new PDO('sqlite::memory:'), $prefix);
    }

    public function dialect(): string
    {
        return $this->dialect;
    }

    public function prefix(): string
    {
        return $this->prefix;
    }

    public function table(string $name): string
    {
        return $this->prefix . Statement::identifier($name);
    }

    public function select(string $sql, array $params = []): array
    {
        return array_map(self::textRow(...), array_values($this->run($sql, $params)->fetchAll()));
    }

    public function selectRow(string $sql, array $params = []): ?array
    {
        $row = $this->run($sql, $params)->fetch();

        return $row === false ? null : self::textRow($row);
    }

    public function selectValue(string $sql, array $params = []): ?string
    {
        $row = $this->selectRow($sql, $params);

        return $row === null ? null : reset($row);
    }

    public function execute(string $sql, array $params = []): int
    {
        return $this->run($sql, $params)->rowCount();
    }

    public function insert(string $table, array $row): int
    {
        [$sql, $params] = Statement::insert($this->table($table), $row);
        $this->run($sql, $params);

        return (int) $this->pdo->lastInsertId();
    }

    public function insertIgnore(string $table, array $row): bool
    {
        [$sql, $params] = Statement::insertIgnore($this->dialect, $this->table($table), $row);

        return $this->run($sql, $params)->rowCount() > 0;
    }

    public function upsert(string $table, array $row, array $uniqueColumns): void
    {
        [$sql, $params] = Statement::upsert($this->dialect, $this->table($table), $row, $uniqueColumns);
        $this->run($sql, $params);
    }

    public function update(string $table, array $values, array $where): int
    {
        [$sql, $params] = Statement::update($this->table($table), $values, $where);

        return $this->run($sql, $params)->rowCount();
    }

    public function delete(string $table, array $where): int
    {
        [$sql, $params] = Statement::delete($this->table($table), $where);

        return $this->run($sql, $params)->rowCount();
    }

    /**
     * @param array<array-key, mixed> $params
     */
    private function run(string $sql, array $params): PDOStatement
    {
        if (Statement::placeholders($sql) !== count($params)) {
            throw new InvalidArgumentException('The statement and its parameters do not match.');
        }

        try {
            $statement = $this->pdo->prepare($sql);
            foreach (Statement::normalize($params) as $index => $value) {
                $statement->bindValue($index + 1, $value, match (true) {
                    $value === null => PDO::PARAM_NULL,
                    is_int($value) => PDO::PARAM_INT,
                    default => PDO::PARAM_STR,
                });
            }
            $statement->execute();
        } catch (PDOException $failure) {
            throw new DatabaseException('The database refused a statement: ' . $failure->getMessage(), 0, $failure);
        }

        return $statement;
    }

    /**
     * WordPress returns every value as text; this does the same, so callers are written once.
     *
     * @param array<string, mixed> $row
     *
     * @return array<string, string|null>
     */
    private static function textRow(array $row): array
    {
        return array_map(static fn (mixed $value): ?string => match (true) {
            $value === null => null,
            is_float($value) => Statement::floatText($value),
            default => (string) $value,
        }, $row);
    }

    private function beginTransaction(): void
    {
        $this->pdo->beginTransaction();
    }

    private function commitTransaction(): void
    {
        $this->pdo->commit();
    }

    private function rollBackTransaction(): void
    {
        if ($this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
    }
}
