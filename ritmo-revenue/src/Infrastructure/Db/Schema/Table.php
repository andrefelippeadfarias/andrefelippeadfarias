<?php

declare(strict_types=1);

namespace Ritmo\Infrastructure\Db\Schema;

use InvalidArgumentException;
use Ritmo\Infrastructure\Db\Statement;

/**
 * The definition of one plugin table: the single description both dialects are rendered from.
 * Every table starts with the auto-incremented "id" column.
 */
final class Table
{
    /** @var list<Column> */
    public readonly array $columns;

    /** @var list<Index> */
    public readonly array $indexes;

    /**
     * @param string       $name    table name without the prefix
     * @param list<Column> $columns columns after "id"
     * @param list<Index>  $indexes
     *
     * @throws InvalidArgumentException on a duplicate name or an index over an unknown column
     */
    public function __construct(public readonly string $name, array $columns, array $indexes = [])
    {
        Statement::identifier($name);
        $columns = [Column::id(), ...array_values($columns)];
        $names = array_map(static fn (Column $column): string => $column->name, $columns);
        if (count($names) !== count(array_unique($names))) {
            throw new InvalidArgumentException(sprintf('Table "%s" repeats a column.', $name));
        }

        $indexNames = [];
        foreach ($indexes as $index) {
            if (array_diff($index->columns, $names) !== [] || in_array($index->name, $indexNames, true)) {
                throw new InvalidArgumentException(sprintf('Index "%s" of table "%s" is invalid.', $index->name, $name));
            }
            $indexNames[] = $index->name;
        }

        $this->columns = $columns;
        $this->indexes = array_values($indexes);
    }

    /**
     * @return list<string>
     */
    public function columnNames(): array
    {
        return array_map(static fn (Column $column): string => $column->name, $this->columns);
    }
}
