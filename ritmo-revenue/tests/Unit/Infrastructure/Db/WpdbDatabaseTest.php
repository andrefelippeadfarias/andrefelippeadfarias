<?php

declare(strict_types=1);

namespace Ritmo\Tests\Unit\Infrastructure\Db;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Ritmo\Infrastructure\Db\Database;
use Ritmo\Infrastructure\Db\DatabaseException;
use Ritmo\Infrastructure\Db\WpdbDatabase;
use Ritmo\Tests\Unit\Infrastructure\Support\FakeWpdb;
use RuntimeException;

final class WpdbDatabaseTest extends TestCase
{
    private FakeWpdb $wpdb;

    private WpdbDatabase $db;

    protected function setUp(): void
    {
        $this->wpdb = new FakeWpdb();
        $this->wpdb->pdo->exec('CREATE TABLE wp_ritmo_things (id INTEGER PRIMARY KEY AUTOINCREMENT, code TEXT NOT NULL UNIQUE, amount REAL, note TEXT)');
        $this->db = new WpdbDatabase($this->wpdb);
    }

    public function testItUsesTheSitePrefixAndSpeaksMySql(): void
    {
        self::assertSame(Database::MYSQL, $this->db->dialect());
        self::assertSame('wp_ritmo_', $this->db->prefix());
        self::assertSame('wp_ritmo_bookings', $this->db->table('bookings'));
        self::assertSame('DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_520_ci', $this->db->charsetCollate());

        $this->wpdb->prefix = 'site2_';
        self::assertSame('site2_ritmo_bookings', (new WpdbDatabase($this->wpdb))->table('bookings'));
    }

    public function testEveryValueGoesThroughPrepareWithAFormatForItsType(): void
    {
        $this->db->insert('things', ['code' => 'a', 'amount' => 12.5, 'note' => null]);
        $this->db->select('SELECT * FROM wp_ritmo_things WHERE id = ? AND code = ? AND amount > ? AND note IS NOT ?', [1, 'a', 0.5, null]);

        self::assertSame(
            [
                ['INSERT INTO wp_ritmo_things (code, amount, note) VALUES (%s, %s, NULL)', ['a', '12.5']],
                ['SELECT * FROM wp_ritmo_things WHERE id = %d AND code = %s AND amount > %s AND note IS NOT NULL', [1, 'a', '0.5']],
            ],
            $this->wpdb->prepared
        );
    }

    public function testAStatementWithoutValuesIsNotPrepared(): void
    {
        self::assertSame('0', $this->db->selectValue('SELECT COUNT(*) FROM wp_ritmo_things'));
        self::assertSame([], $this->wpdb->prepared);
    }

    public function testHostileValuesStayValues(): void
    {
        $hostile = "x'); DROP TABLE wp_ritmo_things; -- %s %d 100%";
        $this->db->insert('things', ['code' => $hostile]);

        self::assertSame($hostile, $this->db->selectValue('SELECT code FROM wp_ritmo_things WHERE code = ?', [$hostile]));
        self::assertSame('1', $this->db->selectValue('SELECT COUNT(*) FROM wp_ritmo_things'));
    }

    public function testAPercentSignInTheStatementIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->db->select("SELECT * FROM wp_ritmo_things WHERE code LIKE 'a%'");
    }

    public function testParametersMustMatchThePlaceholders(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->db->select('SELECT * FROM wp_ritmo_things WHERE code = ?', ['a', 'b']);
    }

    public function testRowsReadAndWriteLikeTheOtherImplementation(): void
    {
        self::assertSame(1, $this->db->insert('things', ['code' => 'a', 'amount' => 0.1 + 0.2, 'note' => 'first']));
        self::assertSame(2, $this->db->insert('things', ['code' => 'b', 'amount' => 2.0]));

        self::assertSame(0.1 + 0.2, (float) $this->db->selectValue('SELECT amount FROM wp_ritmo_things WHERE code = ?', ['a']));
        self::assertSame(['code' => 'b', 'note' => null], $this->db->selectRow('SELECT code, note FROM wp_ritmo_things WHERE id = ?', [2]));
        self::assertNull($this->db->selectRow('SELECT * FROM wp_ritmo_things WHERE id = ?', [9]));
        self::assertNull($this->db->selectValue('SELECT note FROM wp_ritmo_things WHERE id = ?', [2]));

        self::assertSame(1, $this->db->update('things', ['note' => 'changed'], ['code' => 'a']));
        self::assertSame(1, $this->db->delete('things', ['note' => null]));
        self::assertSame([['code' => 'a', 'note' => 'changed']], $this->db->select('SELECT code, note FROM wp_ritmo_things'));
    }

    public function testUpsertAndInsertIgnoreUseTheMySqlSpelling(): void
    {
        $this->db->upsert('things', ['code' => 'a', 'amount' => 1.0, 'note' => 'first'], ['code']);
        $this->db->upsert('things', ['code' => 'a', 'amount' => 2.0, 'note' => 'second'], ['code']);
        self::assertTrue($this->db->insertIgnore('things', ['code' => 'b']));
        self::assertFalse($this->db->insertIgnore('things', ['code' => 'b']));

        self::assertSame([['code' => 'a', 'note' => 'second'], ['code' => 'b', 'note' => null]], $this->db->select('SELECT code, note FROM wp_ritmo_things ORDER BY code'));
        self::assertStringContainsString('ON DUPLICATE KEY UPDATE amount = VALUES(amount), note = VALUES(note)', $this->wpdb->queries[0]);
        self::assertStringStartsWith('INSERT IGNORE INTO wp_ritmo_things', $this->wpdb->queries[2]);
    }

    public function testATransactionCommitsOrRollsBackAsAWhole(): void
    {
        self::assertSame('ok', $this->db->transaction(function (): string {
            $this->db->insert('things', ['code' => 'a']);

            return $this->db->transaction(fn (): string => 'ok');
        }));
        self::assertSame(['START TRANSACTION', 'COMMIT'], [$this->wpdb->queries[0], $this->wpdb->queries[2]]);

        try {
            $this->db->transaction(function (): void {
                $this->db->insert('things', ['code' => 'b']);

                throw new RuntimeException('stop');
            });
            self::fail('The failure must be rethrown.');
        } catch (RuntimeException $failure) {
            self::assertSame('stop', $failure->getMessage());
        }

        self::assertSame('ROLLBACK', $this->wpdb->queries[count($this->wpdb->queries) - 1]);
        self::assertSame('1', $this->db->selectValue('SELECT COUNT(*) FROM wp_ritmo_things'));
    }

    public function testAFailedStatementThrowsAndLeavesErrorDisplayAsItWas(): void
    {
        $this->wpdb->suppress_errors(false);

        try {
            $this->db->execute('INSERT INTO wp_ritmo_missing (code) VALUES (?)', ['a']);
            self::fail('A failed statement must throw.');
        } catch (DatabaseException $failure) {
            self::assertStringContainsString('wp_ritmo_missing', $failure->getMessage());
        }
        try {
            $this->db->select('SELECT * FROM wp_ritmo_missing');
            self::fail('A failed query must throw.');
        } catch (DatabaseException) {
        }

        self::assertFalse($this->wpdb->suppress_errors(false), 'the previous error display setting is restored');
    }

    public function testFloatsComeBackExactWhateverThePrecisionSettingAndTheSettingIsRestored(): void
    {
        $previous = (string) ini_set('precision', '6');
        try {
            $this->db->insert('things', ['code' => 'a', 'amount' => 123456.789012345]);

            self::assertSame(123456.789012345, (float) $this->db->selectValue('SELECT amount FROM wp_ritmo_things WHERE code = ?', ['a']));
            self::assertSame(123456.789012345, (float) $this->db->selectRow('SELECT amount FROM wp_ritmo_things')['amount']);
            self::assertSame('6', ini_get('precision'), 'the setting of the site is put back after every call');

            try {
                $this->db->select('SELECT * FROM wp_ritmo_missing');
            } catch (DatabaseException) {
            }
            self::assertSame('6', ini_get('precision'), 'also after a failure');
        } finally {
            ini_set('precision', $previous);
        }
    }

    public function testStatementsThatReturnTrueCountAsZeroRows(): void
    {
        self::assertSame(0, $this->db->execute('CREATE TABLE wp_ritmo_more (id INTEGER PRIMARY KEY)'));
    }
}
