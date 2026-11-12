<?php

declare(strict_types=1);

namespace Ritmo\Infrastructure\Db\Migration;

use Closure;
use InvalidArgumentException;
use Ritmo\Contracts\KeyValueStore;
use Ritmo\Infrastructure\Db\Database;
use Ritmo\Infrastructure\Db\Schema\DdlRenderer;
use Ritmo\Infrastructure\Db\Schema\SchemaInspector;
use Ritmo\Infrastructure\Db\Schema\Table;

/**
 * Brings the plugin's tables up to the latest migration and remembers the version reached
 * (KeyValueStore key "db_version", the option ritmo_db_version in WordPress).
 *
 * Running it again changes nothing, and it never drops a column or a row. In WordPress the
 * tables are created through dbDelta():
 *
 *     require_once ABSPATH . 'wp-admin/includes/upgrade.php';
 *     Migrator::standard($db, $store, static fn (string $sql) => dbDelta($sql), $db->charsetCollate())->migrate();
 *
 * Without dbDelta (tests, command line) the statements run directly.
 */
final class Migrator
{
    public const VERSION_KEY = 'db_version';

    /** @var list<Migration> */
    private readonly array $migrations;

    private readonly DdlRenderer $renderer;

    private readonly SchemaInspector $inspector;

    /**
     * @param list<Migration>              $migrations     versions 1, 2, 3... in order
     * @param (Closure(string): mixed)|null $dbDelta        WordPress's dbDelta(), when available
     * @param string                       $charsetCollate MySQL table options of the site
     *
     * @throws InvalidArgumentException when the versions are not exactly 1..n in order
     */
    public function __construct(
        private readonly Database $db,
        private readonly KeyValueStore $store,
        array $migrations,
        private readonly ?Closure $dbDelta = null,
        private readonly string $charsetCollate = '',
    ) {
        foreach (array_values($migrations) as $index => $migration) {
            if ($migration->version() !== $index + 1) {
                throw new InvalidArgumentException('Migrations must be numbered 1, 2, 3... in order, without gaps.');
            }
        }

        $this->migrations = array_values($migrations);
        $this->renderer = new DdlRenderer();
        $this->inspector = new SchemaInspector($db);
    }

    /**
     * A migrator with the plugin's own migrations.
     *
     * @param (Closure(string): mixed)|null $dbDelta
     */
    public static function standard(Database $db, KeyValueStore $store, ?Closure $dbDelta = null, string $charsetCollate = ''): self
    {
        return new self($db, $store, Migrations::all(), $dbDelta, $charsetCollate);
    }

    /**
     * The version the database is at; 0 before the first migration.
     */
    public function currentVersion(): int
    {
        $stored = $this->store->get(self::VERSION_KEY, 0);

        return is_numeric($stored) ? max(0, (int) $stored) : 0;
    }

    public function latestVersion(): int
    {
        return count($this->migrations);
    }

    public function isUpToDate(): bool
    {
        return $this->currentVersion() >= $this->latestVersion();
    }

    /**
     * Applies every migration above the current version, in order.
     *
     * @return list<int> the versions applied; empty when the database was up to date
     */
    public function migrate(): array
    {
        $applied = [];
        foreach ($this->migrations as $migration) {
            if ($migration->version() <= $this->currentVersion()) {
                continue;
            }
            foreach ($migration->tables() as $table) {
                $this->install($table);
            }
            $this->store->set(self::VERSION_KEY, $migration->version());
            $applied[] = $migration->version();
        }

        return $applied;
    }

    /**
     * Every plugin table as the latest migration that mentions it defines it.
     *
     * @return array<string, Table> keyed by table name without the prefix
     */
    public function tables(): array
    {
        $tables = [];
        foreach ($this->migrations as $migration) {
            foreach ($migration->tables() as $table) {
                $tables[$table->name] = $table;
            }
        }

        return $tables;
    }

    /**
     * Drops every plugin table and forgets the version. For the uninstall handler only:
     * everything the plugin stored in its tables is lost.
     */
    public function uninstall(): void
    {
        foreach (array_keys($this->tables()) as $name) {
            $this->db->execute('DROP TABLE IF EXISTS ' . $this->db->table($name));
        }
        $this->store->delete(self::VERSION_KEY);
    }

    private function install(Table $table): void
    {
        $name = $this->db->table($table->name);
        if ($this->dbDelta !== null) {
            ($this->dbDelta)($this->renderer->forDbDelta($table, $name, $this->charsetCollate));

            return;
        }

        $dialect = $this->db->dialect();
        $existing = $this->inspector->columns($table->name);
        if ($existing === []) {
            foreach ($this->renderer->create($table, $name, $dialect, $this->charsetCollate) as $statement) {
                $this->db->execute($statement);
            }

            return;
        }

        foreach ($table->columns as $column) {
            if (!in_array($column->name, $existing, true)) {
                $this->db->execute($this->renderer->addColumn($column, $name, $dialect));
            }
        }
        // On MySQL, a new index of an existing table is added by dbDelta; the direct path covers SQLite.
        if ($dialect === Database::SQLITE) {
            foreach ($table->indexes as $index) {
                $this->db->execute($this->renderer->sqliteIndex($index, $name));
            }
        }
    }
}
