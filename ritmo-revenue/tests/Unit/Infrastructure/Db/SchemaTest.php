<?php

declare(strict_types=1);

namespace Ritmo\Tests\Unit\Infrastructure\Db;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Ritmo\Infrastructure\Db\Database;
use Ritmo\Infrastructure\Db\Schema\Column;
use Ritmo\Infrastructure\Db\Schema\DdlRenderer;
use Ritmo\Infrastructure\Db\Schema\Index;
use Ritmo\Infrastructure\Db\Schema\SchemaInspector;
use Ritmo\Infrastructure\Db\Schema\Table;

final class SchemaTest extends TestCase
{
    public function testTheMySqlTextFollowsTheFormatDbDeltaParses(): void
    {
        $sql = (new DdlRenderer())->forDbDelta($this->table(), 'wp_ritmo_samples', 'DEFAULT CHARACTER SET utf8mb4');

        self::assertSame(
            <<<'SQL'
            CREATE TABLE wp_ritmo_samples (
              id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
              code varchar(32) NOT NULL,
              label varchar(190) NOT NULL DEFAULT '',
              units int(11) NOT NULL DEFAULT '1',
              run_id bigint(20) DEFAULT NULL,
              active tinyint(1) NOT NULL DEFAULT '1',
              price double DEFAULT NULL,
              details longtext NOT NULL,
              night date NOT NULL,
              seen_at datetime DEFAULT NULL,
              PRIMARY KEY  (id),
              UNIQUE KEY code (code),
              KEY night (night,units)
            ) DEFAULT CHARACTER SET utf8mb4
            SQL,
            $sql
        );
    }

    public function testRunDirectlyTheMySqlTextCreatesOnlyWhenMissing(): void
    {
        $statements = (new DdlRenderer())->create($this->table(), 'wp_ritmo_samples', Database::MYSQL);

        self::assertCount(1, $statements);
        self::assertStringStartsWith("CREATE TABLE IF NOT EXISTS wp_ritmo_samples (\n", $statements[0]);
        self::assertStringEndsWith("\n)", $statements[0]);
    }

    public function testTheSqliteTextCreatesTheTableAndItsIndexes(): void
    {
        self::assertSame(
            [
                <<<'SQL'
                CREATE TABLE IF NOT EXISTS wp_ritmo_samples (
                  id INTEGER PRIMARY KEY AUTOINCREMENT,
                  code TEXT NOT NULL,
                  label TEXT NOT NULL DEFAULT '',
                  units INTEGER NOT NULL DEFAULT 1,
                  run_id INTEGER,
                  active INTEGER NOT NULL DEFAULT 1,
                  price REAL,
                  details TEXT NOT NULL,
                  night TEXT NOT NULL,
                  seen_at TEXT
                )
                SQL,
                'CREATE UNIQUE INDEX IF NOT EXISTS wp_ritmo_samples_code ON wp_ritmo_samples (code)',
                'CREATE INDEX IF NOT EXISTS wp_ritmo_samples_night ON wp_ritmo_samples (night, units)',
            ],
            (new DdlRenderer())->create($this->table(), 'wp_ritmo_samples', Database::SQLITE)
        );
    }

    public function testAddingAColumnInEachDialect(): void
    {
        $renderer = new DdlRenderer();
        $column = Column::int('stars')->default(3);

        self::assertSame("ALTER TABLE t ADD COLUMN stars int(11) NOT NULL DEFAULT '3'", $renderer->addColumn($column, 't', Database::MYSQL));
        self::assertSame('ALTER TABLE t ADD COLUMN stars INTEGER NOT NULL DEFAULT 3', $renderer->addColumn($column, 't', Database::SQLITE));
        self::assertSame('ALTER TABLE t ADD COLUMN remarks TEXT', $renderer->addColumn(Column::longText('remarks')->nullable(), 't', Database::SQLITE));

        $this->expectException(InvalidArgumentException::class);
        $renderer->addColumn(Column::date('since'), 't', Database::SQLITE);
    }

    public function testColumnsRefuseWhatDoesNotWorkOnBothDatabases(): void
    {
        foreach ([
            static fn () => Column::text('code', 191),
            static fn () => Column::text('code', 0),
            static fn () => Column::text('bad name', 10),
            static fn () => Column::longText('details')->default('x'),
            static fn () => Column::date('night')->default('2026-01-01'),
            static fn () => Column::int('units')->default('one'),
            static fn () => Column::text('status', 16)->default(1),
            static fn () => Column::text('status', 16)->default("it's"),
            static fn () => Column::id()->nullable(),
        ] as $call) {
            try {
                $call();
                self::fail('The column must be refused.');
            } catch (InvalidArgumentException) {
            }
        }
        $this->addToAssertionCount(9);
    }

    public function testTablesRefuseRepeatedColumnsAndBrokenIndexes(): void
    {
        foreach ([
            static fn () => new Table('t', [Column::int('a'), Column::int('a')]),
            static fn () => new Table('t', [Column::int('id')]),
            static fn () => new Table('t', [Column::int('a')], [Index::plain('missing', 'b')]),
            static fn () => new Table('t', [Column::int('a')], [Index::plain('twice', 'a'), Index::unique('twice', 'a')]),
            static fn () => new Table('bad name', [Column::int('a')]),
            static fn () => Index::plain('empty'),
            static fn () => Index::unique('bad name', 'a'),
        ] as $call) {
            try {
                $call();
                self::fail('The table must be refused.');
            } catch (InvalidArgumentException) {
            }
        }
        $this->addToAssertionCount(7);

        self::assertSame(['id', 'a'], (new Table('t', [Column::int('a')]))->columnNames());
    }

    public function testOnMySqlTheInspectorAsksWithShowStatementsAndMatchesTheExactName(): void
    {
        $db = $this->createStub(Database::class);
        $db->method('dialect')->willReturn(Database::MYSQL);
        $db->method('table')->willReturnCallback(static fn (string $name): string => 'wp_ritmo_' . $name);
        $db->method('select')->willReturnCallback(static function (string $sql, array $params = []): array {
            if ($sql === 'SHOW TABLES LIKE ?') {
                // "_" matches any character in LIKE: the lookalike is returned for both names.
                return $params === ['wp_ritmo_runs'] || $params === ['wp_ritmo_ru_s']
                    ? [['Tables_in_site (wp_ritmo_runs)' => 'wp_ritmo_runs']]
                    : [];
            }

            return $sql === 'SHOW COLUMNS FROM wp_ritmo_runs' ? [['Field' => 'id', 'Type' => 'bigint'], ['Field' => 'status', 'Type' => 'varchar(16)']] : [];
        });
        $inspector = new SchemaInspector($db);

        self::assertSame(['id', 'status'], $inspector->columns('runs'));
        self::assertTrue($inspector->tableExists('runs'));
        self::assertSame([], $inspector->columns('ru_s'), 'a lookalike table name is not the table');
        self::assertFalse($inspector->tableExists('alerts'));
    }

    private function table(): Table
    {
        return new Table('samples', [
            Column::text('code', 32),
            Column::text('label', 190)->default(''),
            Column::int('units')->default(1),
            Column::bigInt('run_id')->nullable(),
            Column::bool('active')->default(1),
            Column::float('price')->nullable(),
            Column::longText('details'),
            Column::date('night'),
            Column::dateTime('seen_at')->nullable(),
        ], [Index::unique('code', 'code'), Index::plain('night', 'night', 'units')]);
    }
}
