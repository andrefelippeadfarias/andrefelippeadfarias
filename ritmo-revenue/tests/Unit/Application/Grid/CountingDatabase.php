<?php

declare(strict_types=1);

namespace Ritmo\Tests\Unit\Application\Grid;

use Ritmo\Infrastructure\Db\Database;

/**
 * A Database that counts the statements sent through it (admin spec 9.2): a read model must not
 * send more statements for more nights or entries.
 */
final class CountingDatabase implements Database
{
    public int $statements = 0;

    public function __construct(private readonly Database $inner)
    {
    }

    public function dialect(): string
    {
        return $this->inner->dialect();
    }

    public function prefix(): string
    {
        return $this->inner->prefix();
    }

    public function table(string $name): string
    {
        return $this->inner->table($name);
    }

    public function select(string $sql, array $params = []): array
    {
        ++$this->statements;

        return $this->inner->select($sql, $params);
    }

    public function selectRow(string $sql, array $params = []): ?array
    {
        ++$this->statements;

        return $this->inner->selectRow($sql, $params);
    }

    public function selectValue(string $sql, array $params = []): ?string
    {
        ++$this->statements;

        return $this->inner->selectValue($sql, $params);
    }

    public function execute(string $sql, array $params = []): int
    {
        ++$this->statements;

        return $this->inner->execute($sql, $params);
    }

    public function insert(string $table, array $row): int
    {
        ++$this->statements;

        return $this->inner->insert($table, $row);
    }

    public function insertIgnore(string $table, array $row): bool
    {
        ++$this->statements;

        return $this->inner->insertIgnore($table, $row);
    }

    public function upsert(string $table, array $row, array $uniqueColumns): void
    {
        ++$this->statements;
        $this->inner->upsert($table, $row, $uniqueColumns);
    }

    public function update(string $table, array $values, array $where): int
    {
        ++$this->statements;

        return $this->inner->update($table, $values, $where);
    }

    public function delete(string $table, array $where): int
    {
        ++$this->statements;

        return $this->inner->delete($table, $where);
    }

    public function transaction(callable $work): mixed
    {
        return $this->inner->transaction($work);
    }
}
