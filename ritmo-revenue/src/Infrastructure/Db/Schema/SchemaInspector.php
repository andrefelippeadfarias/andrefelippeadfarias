<?php

declare(strict_types=1);

namespace Ritmo\Infrastructure\Db\Schema;

use Ritmo\Infrastructure\Db\Database;

/**
 * Reads what exists in the database, for the migrator and the diagnostics screen.
 */
final class SchemaInspector
{
    public function __construct(private readonly Database $db)
    {
    }

    /**
     * Column names of a plugin table, in order; an empty list when the table does not exist.
     *
     * @param string $table table name without the prefix
     *
     * @return list<string>
     */
    public function columns(string $table): array
    {
        $name = $this->db->table($table);
        if ($this->db->dialect() === Database::SQLITE) {
            return array_map(
                static fn (array $row): string => (string) $row['name'],
                $this->db->select('SELECT name FROM pragma_table_info(?)', [$name])
            );
        }

        // SHOW statements, because they are also understood when WordPress runs on SQLite.
        // "_" is a wildcard in LIKE, so the answer is compared with the exact name.
        $found = false;
        foreach ($this->db->select('SHOW TABLES LIKE ?', [$name]) as $row) {
            $found = $found || in_array($name, $row, true);
        }
        if (!$found) {
            return [];
        }

        return array_map(
            static fn (array $row): string => (string) $row['Field'],
            $this->db->select('SHOW COLUMNS FROM ' . $name)
        );
    }

    public function tableExists(string $table): bool
    {
        return $this->columns($table) !== [];
    }
}
