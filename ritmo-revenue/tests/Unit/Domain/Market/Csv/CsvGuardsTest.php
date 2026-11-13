<?php

declare(strict_types=1);

namespace Ritmo\Tests\Unit\Domain\Market\Csv;

use PHPUnit\Framework\TestCase;
use Ritmo\Domain\Market\Csv\CsvBytes;
use Ritmo\Domain\Market\Csv\CsvValues;

final class CsvGuardsTest extends TestCase
{
    public function testFilesThatAreNotTextAreRecognised(): void
    {
        foreach (["<?php echo 1;", "  <?=1", "<html><body>", "<!DOCTYPE html>", "<script>x</script>", "PK\x03\x04rest", "%PDF-1.7", "competitor,price\0"] as $head) {
            self::assertTrue(CsvBytes::looksBinaryOrCode($head), bin2hex(substr($head, 0, 8)));
        }
        self::assertTrue(CsvBytes::looksBinaryOrCode(CsvBytes::BOM . '<?php'));
        self::assertFalse(CsvBytes::looksBinaryOrCode("competitor,check_in\ncomp-01,2027-01-15"));
        self::assertFalse(CsvBytes::looksBinaryOrCode(CsvBytes::BOM . 'competitor;check_in'));
    }

    public function testEncodingChecks(): void
    {
        self::assertTrue(CsvBytes::isUtf8('Pousada São João'));
        self::assertFalse(CsvBytes::isUtf8("S\xE3o"));
        self::assertTrue(CsvBytes::isUtf8(substr('abc ã', 0, -1), true), 'a character cut by the head read');
        self::assertFalse(CsvBytes::isUtf8(substr('abc ã', 0, -1)));
        self::assertSame('abc', CsvBytes::withoutBom(CsvBytes::BOM . 'abc'));
    }

    public function testTheDelimiterIsTheOneThatSplitsTheHeaderMost(): void
    {
        self::assertSame(',', CsvBytes::delimiter('competitor,check_in,check_out,price'));
        self::assertSame(';', CsvBytes::delimiter(CsvBytes::BOM . 'competitor;check_in;check_out;price'));
        self::assertSame("\t", CsvBytes::delimiter("competitor\tcheck_in\tprice"));
        self::assertSame(';', CsvBytes::delimiter('"a,b";check_in;price'), 'commas inside quotes do not count');
        self::assertSame(',', CsvBytes::delimiter('competitor'));
        self::assertSame('a,b', CsvBytes::firstLine("a,b\r\nc,d"));
    }

    public function testCaptureFormats(): void
    {
        $tz = 'America/Sao_Paulo';
        self::assertSame('2027-01-02T03:00:00+00:00', CsvValues::capture('2027-01-02', $tz)?->format(DATE_ATOM));
        self::assertSame('2027-01-02T12:30:00+00:00', CsvValues::capture('2027-01-02 09:30', $tz)?->format(DATE_ATOM));
        self::assertSame('2027-01-02T12:30:15+00:00', CsvValues::capture('2027-01-02T09:30:15', $tz)?->format(DATE_ATOM));
        self::assertSame('2027-01-02T09:30:00+00:00', CsvValues::capture('2027-01-02T09:30:00Z', $tz)?->format(DATE_ATOM));
        self::assertSame('2027-01-02T07:30:00+00:00', CsvValues::capture('2027-01-02T09:30:00+02:00', $tz)?->format(DATE_ATOM));
        self::assertSame('2027-01-02T07:30:00+00:00', CsvValues::capture('2027-01-02 09:30+0200', $tz)?->format(DATE_ATOM));
        foreach (['2027-02-30', '2027-01-02 24:00', '02/01/2027', 'now', '2027-01-02 9:30', ''] as $bad) {
            self::assertNull(CsvValues::capture($bad, $tz), $bad);
        }
        self::assertTrue(CsvValues::flag('YES'));
        self::assertFalse(CsvValues::flag('0'));
        self::assertNull(CsvValues::flag('2'));
    }
}
