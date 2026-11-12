<?php

declare(strict_types=1);

namespace Ritmo\Tests\Unit\Infrastructure\Db;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Ritmo\Infrastructure\Db\Database;
use Ritmo\Infrastructure\Db\Statement;
use stdClass;

final class StatementTest extends TestCase
{
    public function testInsert(): void
    {
        self::assertSame(
            ['INSERT INTO wp_ritmo_things (code, amount) VALUES (?, ?)', ['a', 1.5]],
            Statement::insert('wp_ritmo_things', ['code' => 'a', 'amount' => 1.5])
        );
    }

    public function testInsertIgnoreInEachDialect(): void
    {
        self::assertSame('INSERT IGNORE INTO t (code) VALUES (?)', Statement::insertIgnore(Database::MYSQL, 't', ['code' => 'a'])[0]);
        self::assertSame('INSERT OR IGNORE INTO t (code) VALUES (?)', Statement::insertIgnore(Database::SQLITE, 't', ['code' => 'a'])[0]);
    }

    public function testUpsertInEachDialect(): void
    {
        $row = ['pms' => 'p', 'pms_ref' => 'r', 'status' => 'new', 'total' => null];

        self::assertSame(
            ['INSERT INTO t (pms, pms_ref, status, total) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE status = VALUES(status), total = VALUES(total)', ['p', 'r', 'new', null]],
            Statement::upsert(Database::MYSQL, 't', $row, ['pms', 'pms_ref'])
        );
        self::assertSame(
            ['INSERT INTO t (pms, pms_ref, status, total) VALUES (?, ?, ?, ?) ON CONFLICT (pms, pms_ref) DO UPDATE SET status = excluded.status, total = excluded.total', ['p', 'r', 'new', null]],
            Statement::upsert(Database::SQLITE, 't', $row, ['pms', 'pms_ref'])
        );
    }

    public function testUpsertWithOnlyTheUniqueColumnsChangesNothingOnConflict(): void
    {
        self::assertSame('INSERT INTO t (code) VALUES (?) ON DUPLICATE KEY UPDATE code = code', Statement::upsert(Database::MYSQL, 't', ['code' => 'a'], ['code'])[0]);
        self::assertSame('INSERT INTO t (code) VALUES (?) ON CONFLICT (code) DO NOTHING', Statement::upsert(Database::SQLITE, 't', ['code' => 'a'], ['code'])[0]);
    }

    public function testUpsertNeedsItsUniqueColumnsInTheRow(): void
    {
        try {
            Statement::upsert(Database::MYSQL, 't', ['code' => 'a'], []);
            self::fail('Unique columns are required.');
        } catch (InvalidArgumentException) {
        }

        $this->expectException(InvalidArgumentException::class);
        Statement::upsert(Database::MYSQL, 't', ['code' => 'a'], ['other']);
    }

    public function testUpdateAndDeleteWriteNullConditionsAsIsNull(): void
    {
        self::assertSame(
            ['UPDATE t SET status = ?, total = ? WHERE code = ? AND note IS NULL', ['x', null, 'a']],
            Statement::update('t', ['status' => 'x', 'total' => null], ['code' => 'a', 'note' => null])
        );
        self::assertSame(['DELETE FROM t WHERE code = ? AND note IS NULL', ['a']], Statement::delete('t', ['code' => 'a', 'note' => null]));
    }

    public function testEmptyRowsAndConditionsAreRefused(): void
    {
        foreach ([
            static fn () => Statement::insert('t', []),
            static fn () => Statement::update('t', [], ['id' => 1]),
            static fn () => Statement::update('t', ['a' => 1], []),
            static fn () => Statement::delete('t', []),
        ] as $call) {
            try {
                $call();
                self::fail('An empty row or condition must be refused.');
            } catch (InvalidArgumentException) {
            }
        }
        $this->addToAssertionCount(4);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function badIdentifiers(): array
    {
        return [
            'empty' => [''],
            'space' => ['a b'],
            'quote' => ["a'b"],
            'statement' => ['a; DROP TABLE b'],
            'starts with a digit' => ['1a'],
            'dash' => ['a-b'],
            'dot' => ['a.b'],
            'too long' => [str_repeat('a', 65)],
        ];
    }

    #[DataProvider('badIdentifiers')]
    public function testIdentifiersArePlainNamesOnly(string $name): void
    {
        $this->expectException(InvalidArgumentException::class);
        Statement::identifier($name);
    }

    public function testIdentifiersOfRowsConditionsAndTablesAreChecked(): void
    {
        self::assertSame('wp_ritmo_plan_items', Statement::identifier('wp_ritmo_plan_items'));
        foreach ([
            static fn () => Statement::insert('t t', ['a' => 1]),
            static fn () => Statement::insert('t', ['a b' => 1]),
            static fn () => Statement::update('t', ['a' => 1], ['b=1 OR 1' => 1]),
            static fn () => Statement::delete('t', ['b) OR (1' => 1]),
        ] as $call) {
            try {
                $call();
                self::fail('A bad identifier must be refused.');
            } catch (InvalidArgumentException) {
            }
        }
        $this->addToAssertionCount(4);
    }

    public function testParametersAreNormalizedForTheDrivers(): void
    {
        self::assertSame([1, 0, 'x', null, 7, '12.5', '0.1', '3'], Statement::normalize([true, false, 'x', null, 7, 12.5, 0.1, 3.0]));
    }

    /**
     * @return array<string, array{float}>
     */
    public static function floats(): array
    {
        return [
            'sum that is not 0.3' => [0.1 + 0.2],
            'third' => [1 / 3],
            'price' => [723.9],
            'tiny' => [1.0e-7],
            'huge' => [1.0e25],
            'many digits' => [123456789.12345678],
            'negative' => [-0.0001],
            'whole' => [5.0],
            'max' => [PHP_FLOAT_MAX],
            'epsilon' => [PHP_FLOAT_EPSILON],
        ];
    }

    #[DataProvider('floats')]
    public function testAFloatBecomesTextThatReadsBackIdentical(float $value): void
    {
        self::assertSame($value, (float) Statement::floatText($value));
    }

    public function testWhatCannotBeStoredIsRefused(): void
    {
        foreach ([NAN, INF, -INF, [1], new stdClass()] as $value) {
            try {
                Statement::normalize([$value]);
                self::fail('The value must be refused.');
            } catch (InvalidArgumentException) {
            }
        }
        $this->addToAssertionCount(5);
    }

    public function testPlaceholdersAreCounted(): void
    {
        self::assertSame(0, Statement::placeholders('SELECT 1'));
        self::assertSame(3, Statement::placeholders('SELECT * FROM t WHERE a = ? AND b IN (?, ?)'));
    }
}
