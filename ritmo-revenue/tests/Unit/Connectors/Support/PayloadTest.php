<?php

declare(strict_types=1);

namespace Ritmo\Tests\Unit\Connectors\Support;

use PHPUnit\Framework\TestCase;
use Ritmo\Connectors\Support\Payload;
use Ritmo\Connectors\Support\Query;

final class PayloadTest extends TestCase
{
    public function testTextIsTrimmedCleanedAndCut(): void
    {
        $row = ['name' => "  Garden\nSuite\x07 ", 'id' => 902001, 'rate' => 1.5, 'list' => ['x'], 'flag' => true, 'long' => str_repeat('é', 10)];

        self::assertSame('Garden Suite', Payload::text($row, 'name'));
        self::assertSame('902001', Payload::text($row, 'id'));
        self::assertSame('1.5', Payload::text($row, 'rate'));
        self::assertSame('', Payload::text($row, 'list'));
        self::assertSame('', Payload::text($row, 'flag'));
        self::assertSame('', Payload::text($row, 'missing'));
        self::assertSame('ééé', Payload::text($row, 'long', 3), 'cut by characters, not bytes');
    }

    public function testNumbersAcceptNumericTextAndRefuseEverythingElse(): void
    {
        $row = ['a' => '610', 'b' => ' -15.5 ', 'c' => 12, 'd' => 'abc', 'e' => true, 'f' => null, 'g' => '1e999', 'h' => ''];

        self::assertSame(610.0, Payload::number($row, 'a'));
        self::assertSame(-15.5, Payload::number($row, 'b'));
        self::assertSame(12.0, Payload::number($row, 'c'));
        foreach (['d', 'e', 'f', 'g', 'h', 'missing'] as $key) {
            self::assertNull(Payload::number($row, $key), $key);
        }
    }

    public function testIntegersPositivesAndCounts(): void
    {
        $row = ['whole' => '3', 'fraction' => 2.5, 'zero' => 0, 'minus' => -1, 'price' => '0.01'];

        self::assertSame(3, Payload::integer($row, 'whole'));
        self::assertNull(Payload::integer($row, 'fraction'));
        self::assertSame(-1, Payload::integer($row, 'minus'));

        self::assertNull(Payload::positive($row, 'zero'), '0 means "no value"');
        self::assertNull(Payload::positive($row, 'minus'), '-1 means "no value"');
        self::assertSame(0.01, Payload::positive($row, 'price'));

        self::assertSame(3, Payload::count($row, 'whole'));
        self::assertNull(Payload::count($row, 'zero'));
        self::assertNull(Payload::count($row, 'fraction'));
    }

    public function testFlags(): void
    {
        foreach ([true, 1, 1.0, '1', 'true', ' YES '] as $value) {
            self::assertTrue(Payload::flag(['v' => $value], 'v'), var_export($value, true));
        }
        foreach ([false, 0, 2, '0', 'false', 'no', '', null, []] as $value) {
            self::assertFalse(Payload::flag(['v' => $value], 'v'), var_export($value, true));
        }
    }

    public function testDatesAndInstants(): void
    {
        $row = [
            'day' => '2027-03-05',
            'stamp' => '2027-03-05T14:22:05Z',
            'offset' => '2027-03-05T11:22:05-03:00',
            'plain' => '2027-03-05 14:22:05',
            'millis' => '2026-09-02T16:33:22.000Z',
            'bad_day' => '2027-02-30',
            'words' => 'yesterday',
            'number' => 20270305,
        ];

        self::assertSame('2027-03-05 00:00:00 UTC', Payload::date($row, 'stamp')?->format('Y-m-d H:i:s T'));
        self::assertSame('2027-03-05', Payload::date($row, 'day')?->format('Y-m-d'));
        self::assertNull(Payload::date($row, 'bad_day'));
        self::assertNull(Payload::date($row, 'number'));

        self::assertSame('2027-03-05T14:22:05+00:00', Payload::instant($row, 'stamp')?->format('c'));
        self::assertSame('2027-03-05T14:22:05+00:00', Payload::instant($row, 'offset')?->format('c'), 'converted to UTC');
        self::assertSame('2027-03-05T14:22:05+00:00', Payload::instant($row, 'plain')?->format('c'), 'no offset is UTC');
        self::assertSame('2026-09-02T16:33:22+00:00', Payload::instant($row, 'millis')?->format('c'));
        self::assertNull(Payload::instant($row, 'words'), 'relative formats are not instants');
        self::assertNull(Payload::instant($row, 'bad_day'));
        self::assertNull(Payload::instant($row, 'missing'));
    }

    public function testRowsKeepOnlyObjects(): void
    {
        self::assertSame([['a' => 1], ['b' => 2]], Payload::rows(['data' => [['a' => 1], 'text', 3, ['b' => 2]]], 'data'));
        self::assertSame([], Payload::rows(['data' => 'none'], 'data'));
    }

    public function testQueryRepeatsListsAndEncodesValues(): void
    {
        self::assertSame(
            'status=confirmed&status=new&includeInvoiceItems=true&skip=false&from=2027-03-01T00%3A00%3A00Z&n=3',
            Query::build(['status' => ['confirmed', 'new'], 'includeInvoiceItems' => true, 'skip' => false, 'from' => '2027-03-01T00:00:00Z', 'n' => 3])
        );
        self::assertSame('https://x.test/v2', Query::append('https://x.test/v2', []));
        self::assertSame('https://x.test/v2?a=1', Query::append('https://x.test/v2', ['a' => 1]));
    }
}
