<?php

declare(strict_types=1);

namespace Ritmo\Tests\Integration\Db;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Ritmo\Infrastructure\Db\Migration\Migration;
use Ritmo\Infrastructure\Db\Migration\Migrations;
use Ritmo\Infrastructure\Db\Migration\Migrator;
use Ritmo\Infrastructure\Db\PdoDatabase;
use Ritmo\Infrastructure\Db\Schema\Column;
use Ritmo\Infrastructure\Db\Schema\Index;
use Ritmo\Infrastructure\Db\Schema\SchemaInspector;
use Ritmo\Infrastructure\Db\Schema\Table;
use Ritmo\Infrastructure\Db\WpdbDatabase;
use Ritmo\Infrastructure\Store\ArrayStore;
use Ritmo\Tests\Unit\Infrastructure\Support\FakeWpdb;

final class MigratorTest extends TestCase
{
    /** The tables of the approved plan, plus the two the infrastructure itself needs and the payloads of a run (M2). */
    private const PLAN_TABLES = [
        'properties', 'room_types', 'relations', 'periods', 'rulesets', 'price_factors', 'competitors', 'price_samples',
        'market_anchor', 'orders', 'bookings', 'runs', 'plan_items', 'write_ops', 'step_ledger', 'change_log', 'alerts',
        'pending_items', 'api_quota_log', 'snapshots',
    ];
    private const OWN_TABLES = ['locks', 'log', 'run_payloads'];

    private PdoDatabase $db;

    private ArrayStore $store;

    protected function setUp(): void
    {
        $this->db = PdoDatabase::inMemory();
        $this->store = new ArrayStore();
    }

    public function testItCreatesEveryTableOfThePlanAndRemembersTheVersion(): void
    {
        $migrator = Migrator::standard($this->db, $this->store);
        self::assertSame(0, $migrator->currentVersion());
        self::assertFalse($migrator->isUpToDate());

        self::assertSame([1, 2, 3, 4, 5, 6], $migrator->migrate());

        self::assertSame(6, $migrator->currentVersion());
        self::assertSame(6, $this->store->get('db_version'));
        self::assertTrue($migrator->isUpToDate());

        $expected = [...self::PLAN_TABLES, ...self::OWN_TABLES];
        sort($expected);
        self::assertSame($expected, $this->tablesInDatabase());
        $defined = array_keys($migrator->tables());
        sort($defined);
        self::assertSame($expected, $defined);
    }

    public function testEveryTableHasExactlyTheColumnsOfItsDefinition(): void
    {
        $migrator = Migrator::standard($this->db, $this->store);
        $migrator->migrate();
        $inspector = new SchemaInspector($this->db);

        foreach ($migrator->tables() as $name => $table) {
            self::assertSame($table->columnNames(), $inspector->columns($name), $name);
            self::assertSame('id', $table->columnNames()[0], $name);
            self::assertTrue($inspector->tableExists($name));
        }
        self::assertSame([], $inspector->columns('never_created'));
        self::assertFalse($inspector->tableExists('never_created'));
    }

    public function testBookingsHaveNoColumnThatIdentifiesAGuest(): void
    {
        Migrator::standard($this->db, $this->store)->migrate();
        $columns = (new SchemaInspector($this->db))->columns('bookings');

        self::assertSame(
            ['id', 'pms', 'pms_ref', 'room_code', 'room_pms_ref', 'arrival', 'departure', 'status', 'channel', 'origin', 'total', 'units', 'booked_at', 'modified_at', 'cancelled_at', 'synced_at'],
            $columns,
            'A new bookings column must be reviewed here: nothing about the guest may be stored.'
        );
        foreach ($columns as $column) {
            self::assertDoesNotMatchRegularExpression(
                '/name|guest|mail|phone|mobile|address|street|city|country|zip|post|document|passport|birth|comment|note|message|title|lang/i',
                $column,
                "bookings.$column looks like guest data"
            );
        }
    }

    public function testNoTableStoresGuestIdentityColumns(): void
    {
        foreach (Migrator::standard($this->db, $this->store)->tables() as $name => $table) {
            foreach ($table->columnNames() as $column) {
                self::assertDoesNotMatchRegularExpression('/guest|first_?name|last_?name|e_?mail|phone|mobile|passport|birth/i', $column, "$name.$column");
            }
        }
    }

    public function testRunningAgainChangesNothing(): void
    {
        $migrator = Migrator::standard($this->db, $this->store);
        $migrator->migrate();
        $this->db->insert('properties', $this->propertyRow());
        $before = $this->schemaSql();

        self::assertSame([], $migrator->migrate());
        self::assertSame([], Migrator::standard($this->db, $this->store)->migrate());

        self::assertSame($before, $this->schemaSql());
        self::assertSame('1', $this->db->selectValue('SELECT COUNT(*) FROM wp_ritmo_properties'));
    }

    public function testALostVersionIsHarmless(): void
    {
        Migrator::standard($this->db, $this->store)->migrate();
        $this->db->insert('properties', $this->propertyRow());
        $before = $this->schemaSql();
        $this->store->delete('db_version');

        self::assertSame([1, 2, 3, 4, 5, 6], Migrator::standard($this->db, $this->store)->migrate());

        self::assertSame($before, $this->schemaSql());
        self::assertSame('1', $this->db->selectValue('SELECT COUNT(*) FROM wp_ritmo_properties'));
        self::assertSame(6, $this->store->get('db_version'));
    }

    public function testAVersionThatIsNotANumberCountsAsZeroAndAFutureVersionIsLeftAlone(): void
    {
        $this->store->set('db_version', 'broken');
        self::assertSame(0, Migrator::standard($this->db, $this->store)->currentVersion());

        $this->store->set('db_version', 99);
        self::assertSame([], Migrator::standard($this->db, $this->store)->migrate());
        self::assertSame(99, $this->store->get('db_version'));
    }

    public function testALaterMigrationAddsColumnsAndIndexesWithoutTouchingData(): void
    {
        $first = $this->migration(1, new Table('things', [Column::text('code', 32)], [Index::unique('code', 'code')]));
        $second = $this->migration(2, new Table('things', [
            Column::text('code', 32),
            Column::int('stars')->default(3),
            Column::longText('remarks')->nullable(),
        ], [Index::unique('code', 'code'), Index::plain('stars', 'stars')]));

        (new Migrator($this->db, $this->store, [$first]))->migrate();
        $this->db->insert('things', ['code' => 'a']);

        self::assertSame([2], (new Migrator($this->db, $this->store, [$first, $second]))->migrate());

        self::assertSame(['id', 'code', 'stars', 'remarks'], (new SchemaInspector($this->db))->columns('things'));
        self::assertSame(['code' => 'a', 'stars' => '3', 'remarks' => null], $this->db->selectRow('SELECT code, stars, remarks FROM wp_ritmo_things'));
        self::assertStringContainsString('wp_ritmo_things_stars', $this->schemaSql());
    }

    public function testAColumnAddedLaterMustBeNullableOrHaveADefault(): void
    {
        $first = $this->migration(1, new Table('things', [Column::text('code', 32)]));
        $second = $this->migration(2, new Table('things', [Column::text('code', 32), Column::date('since')]));
        (new Migrator($this->db, $this->store, [$first]))->migrate();

        $this->expectException(InvalidArgumentException::class);
        (new Migrator($this->db, $this->store, [$first, $second]))->migrate();
    }

    public function testMigrationsMustBeNumberedInOrderWithoutGaps(): void
    {
        $table = new Table('things', [Column::text('code', 32)]);

        $this->expectException(InvalidArgumentException::class);
        new Migrator($this->db, $this->store, [$this->migration(1, $table), $this->migration(3, $table)]);
    }

    public function testThePluginMigrationsAreNumberedFromOneAndDescribed(): void
    {
        foreach (Migrations::all() as $index => $migration) {
            self::assertSame($index + 1, $migration->version());
            self::assertNotSame('', $migration->description());
            self::assertNotSame([], $migration->tables());
        }
    }

    public function testInWordPressEveryTableGoesThroughDbDeltaInItsFormat(): void
    {
        $wpdb = new FakeWpdb();
        $db = new WpdbDatabase($wpdb);
        $received = [];
        $migrator = Migrator::standard($db, $this->store, static function (string $sql) use (&$received): void {
            $received[] = $sql;
        }, $db->charsetCollate());

        self::assertSame([1, 2, 3, 4, 5, 6], $migrator->migrate());

        $definitions = array_sum(array_map(static fn (Migration $migration): int => count($migration->tables()), Migrations::all()));
        self::assertGreaterThan(count(self::PLAN_TABLES) + count(self::OWN_TABLES), $definitions, 'a table restated by a later migration goes through dbDelta again');
        self::assertCount($definitions, $received);
        self::assertSame([], $wpdb->queries, 'with dbDelta the migrator runs no statement of its own');
        foreach ($received as $sql) {
            self::assertMatchesRegularExpression('/^CREATE TABLE wp_ritmo_[a-z_]+ \(\n/', $sql);
            self::assertStringContainsString("\n  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,\n", $sql);
            self::assertStringContainsString("\n  PRIMARY KEY  (id)", $sql, 'dbDelta needs two spaces after PRIMARY KEY');
            self::assertStringEndsWith("\n) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_520_ci", $sql);
            self::assertStringNotContainsString('IF NOT EXISTS', $sql);
            self::assertStringNotContainsString('`', $sql);
            foreach (array_slice(explode("\n", $sql), 1, -1) as $line) {
                self::assertMatchesRegularExpression('/^  (PRIMARY KEY  \(id\)|(UNIQUE )?KEY [a-z_]+ \([a-z0-9_,]+\)|[a-z0-9_]+ [a-z]+(\(\d+\))?( unsigned)?( NOT NULL)?( AUTO_INCREMENT| DEFAULT (NULL|\'[^\']*\'))?),?$/', $line);
            }
        }
    }

    public function testUninstallDropsEveryTableAndForgetsTheVersion(): void
    {
        $migrator = Migrator::standard($this->db, $this->store);
        $migrator->migrate();

        $migrator->uninstall();

        self::assertSame([], $this->tablesInDatabase());
        self::assertFalse($this->store->has('db_version'));
        self::assertSame([1, 2, 3, 4, 5, 6], $migrator->migrate(), 'the plugin can be installed again');
    }

    /**
     * @return list<string> plugin tables present, without the prefix, sorted
     */
    private function tablesInDatabase(): array
    {
        $rows = $this->db->select('SELECT name FROM sqlite_master WHERE type = ? AND name LIKE ? ORDER BY name', ['table', 'wp_ritmo_%']);
        $names = array_map(static fn (array $row): string => substr((string) $row['name'], strlen('wp_ritmo_')), $rows);
        $names = array_values(array_filter($names, static fn (string $name): bool => $name !== ''));
        sort($names);

        return $names;
    }

    private function schemaSql(): string
    {
        $rows = $this->db->select('SELECT sql FROM sqlite_master WHERE sql IS NOT NULL ORDER BY name');

        return implode("\n", array_map(static fn (array $row): string => (string) $row['sql'], $rows));
    }

    /**
     * @return array<string, string>
     */
    private function propertyRow(): array
    {
        return [
            'code' => 'inn', 'name' => 'Inn', 'timezone' => 'UTC', 'currency' => 'USD', 'occupancy_goals' => '{}',
            'created_at' => '2026-11-11 12:00:00', 'updated_at' => '2026-11-11 12:00:00',
        ];
    }

    private function migration(int $version, Table ...$tables): Migration
    {
        return new class ($version, array_values($tables)) implements Migration {
            /**
             * @param list<Table> $tables
             */
            public function __construct(private readonly int $version, private readonly array $tables)
            {
            }

            public function version(): int
            {
                return $this->version;
            }

            public function description(): string
            {
                return 'Test migration ' . $this->version;
            }

            public function tables(): array
            {
                return $this->tables;
            }
        };
    }
}
