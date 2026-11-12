<?php

declare(strict_types=1);

namespace Ritmo\Infrastructure\Db\Schema;

use InvalidArgumentException;
use Ritmo\Infrastructure\Db\Database;

/**
 * Renders a Table as SQL for MySQL and for SQLite.
 *
 * The MySQL text follows the format WordPress's dbDelta() parses: one column per line, two
 * spaces after PRIMARY KEY, KEY instead of INDEX, no spaces inside key column lists.
 */
final class DdlRenderer
{
    /**
     * The CREATE TABLE statement to hand to dbDelta(), which creates the table or brings an
     * existing one up to the definition.
     */
    public function forDbDelta(Table $table, string $tableName, string $charsetCollate = ''): string
    {
        return $this->mysqlCreate($table, 'CREATE TABLE ' . $tableName, $charsetCollate);
    }

    /**
     * Statements that create the table and its indexes when they do not exist, for running
     * directly against the database.
     *
     * @return list<string>
     */
    public function create(Table $table, string $tableName, string $dialect, string $charsetCollate = ''): array
    {
        if ($dialect === Database::MYSQL) {
            return [$this->mysqlCreate($table, 'CREATE TABLE IF NOT EXISTS ' . $tableName, $charsetCollate)];
        }

        $lines = array_map(fn (Column $column): string => '  ' . $this->sqliteColumn($column), $table->columns);
        $statements = [sprintf("CREATE TABLE IF NOT EXISTS %s (\n%s\n)", $tableName, implode(",\n", $lines))];
        foreach ($table->indexes as $index) {
            $statements[] = $this->sqliteIndex($index, $tableName);
        }

        return $statements;
    }

    /**
     * The statement that adds one column to an existing table.
     *
     * @throws InvalidArgumentException when the column is NOT NULL without a default: SQLite cannot add it
     */
    public function addColumn(Column $column, string $tableName, string $dialect): string
    {
        if (!$column->nullable && $column->default === null) {
            throw new InvalidArgumentException(sprintf('Column "%s" must be nullable or have a default to be added to an existing table.', $column->name));
        }

        return sprintf('ALTER TABLE %s ADD COLUMN %s', $tableName, $dialect === Database::MYSQL ? $this->mysqlColumn($column) : $this->sqliteColumn($column));
    }

    /**
     * SQLite: the statement that creates one index when it does not exist. Index names are
     * global in SQLite, so the table name is part of them.
     */
    public function sqliteIndex(Index $index, string $tableName): string
    {
        return sprintf(
            'CREATE %sINDEX IF NOT EXISTS %s_%s ON %s (%s)',
            $index->unique ? 'UNIQUE ' : '',
            $tableName,
            $index->name,
            $tableName,
            implode(', ', $index->columns)
        );
    }

    private function mysqlCreate(Table $table, string $opening, string $charsetCollate): string
    {
        $lines = array_map(fn (Column $column): string => '  ' . $this->mysqlColumn($column), $table->columns);
        $lines[] = '  PRIMARY KEY  (id)';
        foreach ($table->indexes as $index) {
            $lines[] = sprintf('  %s %s (%s)', $index->unique ? 'UNIQUE KEY' : 'KEY', $index->name, implode(',', $index->columns));
        }

        return rtrim(sprintf("%s (\n%s\n) %s", $opening, implode(",\n", $lines), $charsetCollate));
    }

    private function mysqlColumn(Column $column): string
    {
        $definition = $column->name . ' ' . $column->type->mysql($column->length);
        if ($column->type === ColumnType::Id) {
            return $definition . ' NOT NULL AUTO_INCREMENT';
        }
        if ($column->default !== null) {
            return sprintf("%s%s DEFAULT '%s'", $definition, $column->nullable ? '' : ' NOT NULL', $column->default);
        }

        return $definition . ($column->nullable ? ' DEFAULT NULL' : ' NOT NULL');
    }

    private function sqliteColumn(Column $column): string
    {
        $definition = $column->name . ' ' . $column->type->sqlite();
        if ($column->type === ColumnType::Id) {
            return $definition . ' PRIMARY KEY AUTOINCREMENT';
        }
        if (!$column->nullable) {
            $definition .= ' NOT NULL';
        }
        if ($column->default !== null) {
            $definition .= ' DEFAULT ' . (is_string($column->default) ? "'" . $column->default . "'" : $column->default);
        }

        return $definition;
    }
}
