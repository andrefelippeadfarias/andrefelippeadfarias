<?php

declare(strict_types=1);

namespace Ritmo\Infrastructure\Db;

/**
 * The plugin's door to its own tables. Two implementations: WpdbDatabase (WordPress) and
 * PdoDatabase (tests and command line).
 *
 * Rules that make both behave the same:
 * - SQL uses "?" placeholders only. A statement never carries a value, a "?" or a "%" in its
 *   text: every value travels in $params (int, float, string, bool or null).
 * - Table names come from table(); column names are fixed identifiers written in the code.
 * - Every value read comes back as a string or null, whatever the driver. Callers cast.
 * - Failures throw DatabaseException.
 */
interface Database
{
    public const MYSQL = 'mysql';
    public const SQLITE = 'sqlite';

    /**
     * The SQL dialect statements must be written in: self::MYSQL or self::SQLITE.
     */
    public function dialect(): string;

    /**
     * Prefix of every plugin table ("wp_ritmo_").
     */
    public function prefix(): string;

    /**
     * Full name of a plugin table: table('bookings') is "wp_ritmo_bookings".
     */
    public function table(string $name): string;

    /**
     * @param list<int|float|string|bool|null> $params
     *
     * @return list<array<string, string|null>>
     */
    public function select(string $sql, array $params = []): array;

    /**
     * The first row, or null when there is none.
     *
     * @param list<int|float|string|bool|null> $params
     *
     * @return array<string, string|null>|null
     */
    public function selectRow(string $sql, array $params = []): ?array;

    /**
     * The first column of the first row, or null when there is no row.
     *
     * @param list<int|float|string|bool|null> $params
     */
    public function selectValue(string $sql, array $params = []): ?string;

    /**
     * Runs a statement that returns no rows.
     *
     * @param list<int|float|string|bool|null> $params
     *
     * @return int rows affected
     */
    public function execute(string $sql, array $params = []): int;

    /**
     * @param string                                  $table plugin table name without the prefix
     * @param array<string, int|float|string|bool|null> $row   column => value
     *
     * @return int id of the new row
     */
    public function insert(string $table, array $row): int;

    /**
     * Inserts unless the row would break a unique key.
     *
     * @param array<string, int|float|string|bool|null> $row
     *
     * @return bool true when the row was inserted
     */
    public function insertIgnore(string $table, array $row): bool;

    /**
     * Inserts the row, or updates the existing row that has the same values in $uniqueColumns
     * (which must be covered by a unique key of the table).
     *
     * @param array<string, int|float|string|bool|null> $row
     * @param list<string>                              $uniqueColumns
     */
    public function upsert(string $table, array $row, array $uniqueColumns): void;

    /**
     * @param array<string, int|float|string|bool|null> $values column => new value
     * @param array<string, int|float|string|bool|null> $where  column => value, joined by AND; never empty
     *
     * @return int rows matched or changed (driver dependent: do not use it to tell "not found" from "unchanged")
     */
    public function update(string $table, array $values, array $where): int;

    /**
     * @param array<string, int|float|string|bool|null> $where column => value, joined by AND; never empty
     *
     * @return int rows deleted
     */
    public function delete(string $table, array $where): int;

    /**
     * Runs $work inside a transaction and returns its result. An exception rolls everything
     * back and is rethrown. A transaction inside another joins the outer one.
     *
     * @template T
     *
     * @param callable(): T $work
     *
     * @return T
     */
    public function transaction(callable $work): mixed;
}
