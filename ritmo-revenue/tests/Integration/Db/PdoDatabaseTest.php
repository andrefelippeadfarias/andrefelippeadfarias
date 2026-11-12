<?php

declare(strict_types=1);

namespace Ritmo\Tests\Integration\Db;

use InvalidArgumentException;
use PDO;
use PHPUnit\Framework\TestCase;
use Ritmo\Infrastructure\Db\Database;
use Ritmo\Infrastructure\Db\DatabaseException;
use Ritmo\Infrastructure\Db\PdoDatabase;
use RuntimeException;

final class PdoDatabaseTest extends TestCase
{
    private PdoDatabase $db;

    protected function setUp(): void
    {
        $this->db = PdoDatabase::inMemory();
        $this->db->execute('CREATE TABLE wp_ritmo_things (id INTEGER PRIMARY KEY AUTOINCREMENT, code TEXT NOT NULL UNIQUE, amount REAL, note TEXT)');
    }

    public function testItNamesTablesWithThePrefixAndReportsItsDialect(): void
    {
        self::assertSame(Database::SQLITE, $this->db->dialect());
        self::assertSame('wp_ritmo_', $this->db->prefix());
        self::assertSame('wp_ritmo_bookings', $this->db->table('bookings'));
    }

    public function testATableNameThatIsNotAnIdentifierIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->db->table('bookings; DROP TABLE x');
    }

    public function testInsertReturnsTheNewIdAndValuesComeBackAsText(): void
    {
        self::assertSame(1, $this->db->insert('things', ['code' => 'a', 'amount' => 12.5, 'note' => null]));
        self::assertSame(2, $this->db->insert('things', ['code' => 'b', 'amount' => 3, 'note' => 'x']));

        $rows = $this->db->select('SELECT * FROM wp_ritmo_things ORDER BY id');
        self::assertSame(['id' => '1', 'code' => 'a', 'amount' => '12.5', 'note' => null], $rows[0]);
        self::assertSame('3', $rows[1]['amount']);
        self::assertSame('x', $rows[1]['note']);
    }

    public function testSelectRowAndSelectValue(): void
    {
        $this->db->insert('things', ['code' => 'a', 'amount' => 1.5]);

        self::assertSame('a', $this->db->selectRow('SELECT code, amount FROM wp_ritmo_things WHERE code = ?', ['a'])['code'] ?? null);
        self::assertNull($this->db->selectRow('SELECT * FROM wp_ritmo_things WHERE code = ?', ['zz']));
        self::assertSame('1', $this->db->selectValue('SELECT COUNT(*) FROM wp_ritmo_things'));
        self::assertNull($this->db->selectValue('SELECT code FROM wp_ritmo_things WHERE code = ?', ['zz']));
        self::assertNull($this->db->selectValue('SELECT note FROM wp_ritmo_things WHERE code = ?', ['a']));
    }

    public function testFloatsSurviveStorageExactly(): void
    {
        $values = [0.1 + 0.2, 1 / 3, 723.9, 1.0e-7, 123456789.12345678, -0.0001, 5.0];
        foreach ($values as $index => $value) {
            $this->db->insert('things', ['code' => 'f' . $index, 'amount' => $value]);
        }

        foreach ($values as $index => $value) {
            self::assertSame($value, (float) $this->db->selectValue('SELECT amount FROM wp_ritmo_things WHERE code = ?', ['f' . $index]));
        }
    }

    public function testBooleansAreStoredAsZeroAndOne(): void
    {
        $this->db->insert('things', ['code' => 'yes', 'amount' => true]);
        $this->db->insert('things', ['code' => 'no', 'amount' => false]);

        self::assertSame(1.0, (float) $this->db->selectValue('SELECT amount FROM wp_ritmo_things WHERE code = ?', ['yes']));
        self::assertSame(0.0, (float) $this->db->selectValue('SELECT amount FROM wp_ritmo_things WHERE code = ?', ['no']));
    }

    public function testUpdateAndDeleteNeedACondition(): void
    {
        $this->db->insert('things', ['code' => 'a', 'amount' => 1.0]);
        $this->db->insert('things', ['code' => 'b', 'amount' => 2.0]);

        self::assertSame(1, $this->db->update('things', ['amount' => 9.0, 'note' => 'changed'], ['code' => 'a']));
        self::assertSame('9', $this->db->selectValue('SELECT amount FROM wp_ritmo_things WHERE code = ?', ['a']));
        self::assertSame(1, $this->db->delete('things', ['code' => 'b']));
        self::assertSame('1', $this->db->selectValue('SELECT COUNT(*) FROM wp_ritmo_things'));

        try {
            $this->db->update('things', ['amount' => 0.0], []);
            self::fail('An update without a condition must be refused.');
        } catch (InvalidArgumentException) {
        }
        $this->expectException(InvalidArgumentException::class);
        $this->db->delete('things', []);
    }

    public function testANullConditionMatchesNullValues(): void
    {
        $this->db->insert('things', ['code' => 'a', 'note' => null]);
        $this->db->insert('things', ['code' => 'b', 'note' => 'kept']);

        self::assertSame(1, $this->db->delete('things', ['note' => null]));
        self::assertSame('b', $this->db->selectValue('SELECT code FROM wp_ritmo_things'));
    }

    public function testUpsertInsertsThenUpdatesTheSameRow(): void
    {
        $this->db->upsert('things', ['code' => 'a', 'amount' => 1.0, 'note' => 'first'], ['code']);
        $this->db->upsert('things', ['code' => 'a', 'amount' => 2.0, 'note' => 'second'], ['code']);
        $this->db->upsert('things', ['code' => 'b', 'amount' => 3.0, 'note' => null], ['code']);

        self::assertSame(
            [['code' => 'a', 'amount' => '2', 'note' => 'second'], ['code' => 'b', 'amount' => '3', 'note' => null]],
            $this->db->select('SELECT code, amount, note FROM wp_ritmo_things ORDER BY code')
        );
    }

    public function testUpsertWithNothingToUpdateKeepsTheRow(): void
    {
        $this->db->upsert('things', ['code' => 'a'], ['code']);
        $this->db->upsert('things', ['code' => 'a'], ['code']);

        self::assertSame('1', $this->db->selectValue('SELECT COUNT(*) FROM wp_ritmo_things'));
    }

    public function testInsertIgnoreTellsWhetherTheRowWentIn(): void
    {
        self::assertTrue($this->db->insertIgnore('things', ['code' => 'a', 'note' => 'first']));
        self::assertFalse($this->db->insertIgnore('things', ['code' => 'a', 'note' => 'second']));
        self::assertSame('first', $this->db->selectValue('SELECT note FROM wp_ritmo_things WHERE code = ?', ['a']));
    }

    public function testATransactionCommitsAsAWholeAndReturnsTheResult(): void
    {
        $result = $this->db->transaction(function (): string {
            $this->db->insert('things', ['code' => 'a']);
            $this->db->insert('things', ['code' => 'b']);

            return 'done';
        });

        self::assertSame('done', $result);
        self::assertSame('2', $this->db->selectValue('SELECT COUNT(*) FROM wp_ritmo_things'));
    }

    public function testAFailureRollsTheWholeTransactionBackIncludingInnerOnes(): void
    {
        try {
            $this->db->transaction(function (): void {
                $this->db->insert('things', ['code' => 'a']);
                $this->db->transaction(fn (): int => $this->db->insert('things', ['code' => 'b']));

                throw new RuntimeException('stop');
            });
            self::fail('The failure must be rethrown.');
        } catch (RuntimeException $failure) {
            self::assertSame('stop', $failure->getMessage());
        }

        self::assertSame('0', $this->db->selectValue('SELECT COUNT(*) FROM wp_ritmo_things'));

        // The database is usable again and a new transaction commits.
        $this->db->transaction(fn (): int => $this->db->insert('things', ['code' => 'c']));
        self::assertSame('1', $this->db->selectValue('SELECT COUNT(*) FROM wp_ritmo_things'));
    }

    public function testAFailureInAnInnerTransactionRollsTheOuterOneBackToo(): void
    {
        try {
            $this->db->transaction(function (): void {
                $this->db->insert('things', ['code' => 'a']);
                $this->db->transaction(function (): void {
                    $this->db->insert('things', ['code' => 'a']);
                });
            });
            self::fail('The duplicate must fail.');
        } catch (DatabaseException) {
        }

        self::assertSame('0', $this->db->selectValue('SELECT COUNT(*) FROM wp_ritmo_things'));
    }

    public function testABrokenStatementBecomesADatabaseException(): void
    {
        $this->expectException(DatabaseException::class);
        $this->db->select('SELECT * FROM wp_ritmo_missing');
    }

    public function testParametersMustMatchThePlaceholders(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->db->select('SELECT * FROM wp_ritmo_things WHERE code = ? AND note = ?', ['a']);
    }

    public function testValuesNeverTravelInTheStatement(): void
    {
        $hostile = "x'); DROP TABLE wp_ritmo_things; --";
        $this->db->insert('things', ['code' => $hostile]);

        self::assertSame($hostile, $this->db->selectValue('SELECT code FROM wp_ritmo_things WHERE code = ?', [$hostile]));
    }

    public function testAColumnNameThatIsNotAnIdentifierIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->db->insert('things', ['code = 1; --' => 'a']);
    }

    public function testNaNCannotBeStored(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->db->insert('things', ['code' => 'a', 'amount' => NAN]);
    }

    public function testAnUnsupportedDriverIsRefused(): void
    {
        // The constructor only asks the driver name, so no connection is needed.
        $this->expectException(InvalidArgumentException::class);
        new PdoDatabase(new class () extends PDO {
            public function __construct()
            {
            }

            public function getAttribute(int $attribute): mixed
            {
                return 'pgsql';
            }
        });
    }
}
