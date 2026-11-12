<?php

declare(strict_types=1);

namespace Ritmo\Tests\Unit\Infrastructure\Db;

use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Ritmo\Domain\Support\Days;
use Ritmo\Infrastructure\Db\DatabaseException;
use Ritmo\Infrastructure\Db\DbValue;

final class DbValueTest extends TestCase
{
    public function testInstantsAreStoredInUtcToTheSecond(): void
    {
        self::assertSame('2026-11-11 22:53:07', DbValue::instant(new DateTimeImmutable('2026-11-11T19:53:07.987654-03:00')));
        self::assertNull(DbValue::nullableInstant(null));
        self::assertSame('2026-11-11T22:53:07+00:00', DbValue::toInstant('2026-11-11 22:53:07')?->format(DATE_ATOM));
        self::assertSame('2026-11-11T22:53:07+00:00', DbValue::toInstant('2026-11-11 22:53:07.000000')?->format(DATE_ATOM));
        self::assertNull(DbValue::toInstant(null));
        self::assertNull(DbValue::toInstant(''));
    }

    public function testNightsAreStoredAsDates(): void
    {
        self::assertSame('2026-12-24', DbValue::night(Days::date('2026-12-24')));
        self::assertSame('2026-12-24', DbValue::nullableNight(new DateTimeImmutable('2026-12-24T15:00:00Z')));
        self::assertNull(DbValue::nullableNight(null));
        self::assertEquals(Days::date('2026-12-24'), DbValue::toNight('2026-12-24'));
        self::assertEquals(Days::date('2026-12-24'), DbValue::toNight('2026-12-24 00:00:00'));
        self::assertNull(DbValue::toNight(null));
    }

    public function testUnreadableStoredValuesAreDatabaseErrors(): void
    {
        foreach ([
            static fn () => DbValue::toInstant('yesterday'),
            static fn () => DbValue::toNight('2026-02-30'),
            static fn () => DbValue::requireInstant(null),
            static fn () => DbValue::requireNight(''),
            static fn () => DbValue::toArray('{broken'),
            static fn () => DbValue::toArray('"text"'),
            static fn () => DbValue::json(['bad' => "\xB1\x31"]),
        ] as $call) {
            try {
                $call();
                self::fail('An unreadable value must be reported.');
            } catch (DatabaseException) {
            }
        }
        $this->addToAssertionCount(7);
    }

    public function testJsonKeepsFloatsFloatsAndTextReadable(): void
    {
        $value = ['goal' => 60.0, 'name' => 'Pousada São João', 'path' => 'a/b', 'list' => [1, 2.5, null, true]];

        self::assertSame('{"goal":60.0,"name":"Pousada São João","path":"a/b","list":[1,2.5,null,true]}', DbValue::json($value));
        self::assertSame($value, DbValue::toArray(DbValue::json($value)));
        self::assertSame([], DbValue::toArray(null));
        self::assertSame([], DbValue::toArray(''));
    }

    public function testDescriptiveTextIsCutByCharacters(): void
    {
        self::assertSame('ação', DbValue::clip('ação de graças', 4));
        self::assertSame('short', DbValue::clip('short', 64));
    }

    public function testMachineWordsAreCheckedNotCut(): void
    {
        self::assertSame('pace_ahead', DbValue::word('pace_ahead', 16));
        self::assertSame('run:inn-1.v2', DbValue::word('run:inn-1.v2', 16));

        foreach (['', 'has space', 'semi;colon', '_leading', str_repeat('a', 17), 'ação'] as $bad) {
            try {
                DbValue::word($bad, 16);
                self::fail("\"$bad\" must be refused.");
            } catch (InvalidArgumentException) {
            }
        }
        $this->addToAssertionCount(6);
    }

    public function testExternalReferencesAreCheckedOnlyForLength(): void
    {
        self::assertSame('', DbValue::reference('', 8));
        self::assertSame('a/b|c d', DbValue::reference('a/b|c d', 8));
        self::assertSame('çççççççç', DbValue::reference('çççççççç', 8));

        $this->expectException(InvalidArgumentException::class);
        DbValue::reference('123456789', 8);
    }

    public function testNullableNumbers(): void
    {
        self::assertNull(DbValue::nullableFloat(null));
        self::assertSame(12.5, DbValue::nullableFloat('12.5'));
        self::assertNull(DbValue::nullableInt(null));
        self::assertSame(3, DbValue::nullableInt('3'));
    }
}
