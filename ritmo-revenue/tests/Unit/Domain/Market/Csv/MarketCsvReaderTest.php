<?php

declare(strict_types=1);

namespace Ritmo\Tests\Unit\Domain\Market\Csv;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Ritmo\Domain\Market\Csv\CompetitorIndex;
use Ritmo\Domain\Market\Csv\CsvContext;
use Ritmo\Domain\Market\Csv\MarketCsvProblem;
use Ritmo\Domain\Market\Csv\MarketCsvReader;
use Ritmo\Domain\Market\Csv\ParsedRow;
use Ritmo\Domain\Market\Series\RoomBasis;
use Ritmo\Domain\Support\Days;

final class MarketCsvReaderTest extends TestCase
{
    private const HEADER = ['competitor', 'check_in', 'check_out', 'price', 'price_basis', 'room', 'currency', 'captured_at', 'source', 'available'];

    public function testTenValidRowsAndTwoInvalidOnesAreSeparatedWithTheirLineAndReason(): void
    {
        $rows = [self::HEADER];
        for ($i = 0; $i < 10; ++$i) {
            $rows[] = ['comp-01', Days::key(Days::add(Days::date('2027-01-10'), $i)), Days::key(Days::add(Days::date('2027-01-12'), $i)), '1840.00', 'total', 'cheapest', 'BRL', '2026-11-12 09:30', 'booking', '1'];
        }
        $rows[] = ['comp-02', '2027-01-15', '2027-01-17', 'abc', '', '', '', '2026-11-12', '', ''];
        $rows[] = ['comp-01', '2027-01-15', '2027-01-15', '400', '', '', '', '2026-11-12', '', ''];

        $result = self::reader()->read($rows);

        self::assertCount(10, $result->rows);
        self::assertSame(12, $result->total);
        self::assertSame(2, $result->invalidLines());
        $errors = $result->ofKind(MarketCsvProblem::ERROR);
        self::assertSame([[12, 'price', MarketCsvProblem::BAD_PRICE, 'abc'], [13, 'check_out', MarketCsvProblem::CHECKOUT_NOT_AFTER_CHECKIN, '2027-01-15']], array_map(static fn (MarketCsvProblem $p): array => [$p->line, $p->column, $p->code, $p->value], $errors));
        $first = $result->rows[0];
        self::assertSame(2, $first->nights);
        self::assertSame(920.0, $first->price);
        self::assertSame(1840.0, $first->total);
        self::assertSame(RoomBasis::Cheapest, $first->basis);
        self::assertSame('2026-11-12T12:30:00+00:00', $first->capturedAt->format(DATE_ATOM), 'local time of the property (UTC-3) turned into UTC');
        self::assertSame('booking', $first->source);
    }

    public function testDefaultsOfTheOptionalColumns(): void
    {
        $result = self::reader()->read([['Competitor ', 'CHECK_IN', 'check_out', 'price'], ['Competitor 02', '2027-02-01', '2027-02-02', '480,50']]);

        self::assertCount(1, $result->rows);
        $row = $result->rows[0];
        self::assertSame(2, $row->competitorId, 'found by its exact name, any case');
        self::assertSame(480.5, $row->price);
        self::assertSame(480.5, $row->total);
        self::assertSame('csv', $row->source);
        self::assertTrue($row->available);
        self::assertSame('BRL', $row->currency);
        self::assertSame(self::now()->format(DATE_ATOM), $row->capturedAt->format(DATE_ATOM));
        self::assertSame([MarketCsvProblem::CAPTURE_MISSING], array_map(static fn (MarketCsvProblem $p): string => $p->code, $result->ofKind(MarketCsvProblem::WARNING)));
    }

    public function testAPerNightPriceKeepsNoTotalAndAComparableRowIsReadAsSuch(): void
    {
        $result = self::reader()->read([self::HEADER, ['ref-3', '2027-01-15', '2027-01-17', '1125.50', 'per_night', 'comparable', 'brl', '2026-11-12T09:30:00Z', '', 'no']]);

        $row = $result->rows[0];
        self::assertSame(3, $row->competitorId, 'found by its reference');
        self::assertSame(1125.5, $row->price);
        self::assertNull($row->total);
        self::assertSame(RoomBasis::Comparable, $row->basis);
        self::assertFalse($row->available);
        self::assertSame('2026-11-12T09:30:00+00:00', $row->capturedAt->format(DATE_ATOM));
    }

    public function testEveryRowErrorHasItsCode(): void
    {
        $cases = [
            MarketCsvProblem::UNKNOWN_COMPETITOR => ['nobody', '2027-01-15', '2027-01-16', '400', '', '', '', '', '', ''],
            MarketCsvProblem::BAD_DATE => ['comp-01', '15/01/2027', '2027-01-16', '400', '', '', '', '', '', ''],
            MarketCsvProblem::STAY_TOO_LONG => ['comp-01', '2027-01-15', '2027-01-25', '400', '', '', '', '', '', ''],
            MarketCsvProblem::STAY_IN_PAST => ['comp-01', '2026-11-12', '2026-11-14', '400', '', '', '', '', '', ''],
            MarketCsvProblem::BAD_BASIS => ['comp-01', '2027-01-15', '2027-01-16', '400', 'weekly', '', '', '', '', ''],
            MarketCsvProblem::BAD_ROOM => ['comp-01', '2027-01-15', '2027-01-16', '400', '', 'suite', '', '', '', ''],
            MarketCsvProblem::BAD_CURRENCY => ['comp-01', '2027-01-15', '2027-01-16', '400', '', '', 'R$', '', '', ''],
            MarketCsvProblem::CURRENCY_MISMATCH => ['comp-01', '2027-01-15', '2027-01-16', '400', '', '', 'USD', '', '', ''],
            MarketCsvProblem::BAD_CAPTURE => ['comp-01', '2027-01-15', '2027-01-16', '400', '', '', '', 'yesterday', '', ''],
            MarketCsvProblem::CAPTURE_IN_FUTURE => ['comp-01', '2027-01-15', '2027-01-16', '400', '', '', '', '2026-11-13 15:00', '', ''],
            MarketCsvProblem::BAD_AVAILABLE => ['comp-01', '2027-01-15', '2027-01-16', '400', '', '', '', '', '', 'maybe'],
            MarketCsvProblem::BAD_SOURCE => ['comp-01', '2027-01-15', '2027-01-16', '400', '', '', '', '', 'Booking.com', ''],
            MarketCsvProblem::CELL_TOO_LONG => ['comp-01', '2027-01-15', '2027-01-16', '400', '', '', '', '', str_repeat('a', 1001), ''],
            MarketCsvProblem::ROW_NOT_UTF8 => ["comp-\xE9", '2027-01-15', '2027-01-16', '400', '', '', '', '', '', ''],
        ];
        foreach ($cases as $code => $row) {
            $result = self::reader()->read([self::HEADER, $row]);
            self::assertSame([], $result->rows, $code);
            self::assertSame([$code], array_map(static fn (MarketCsvProblem $p): string => $p->code, $result->ofKind(MarketCsvProblem::ERROR)), $code);
        }
    }

    public function testPricesFollowTheDocumentedFormatOnly(): void
    {
        foreach (['480' => 480.0, '480.5' => 480.5, '480,50' => 480.5, '1234567.99' => 1234567.99] as $text => $expected) {
            self::assertSame($expected, self::reader()->read([self::HEADER, ['comp-01', '2027-01-15', '2027-01-16', (string) $text, '', '', '', '', '', '']])->rows[0]->price, (string) $text);
        }
        foreach (['0', '-480', '1.234,50', '1,234', '480.505', 'R$ 480', '12345678', '4e2', ''] as $text) {
            self::assertSame([], self::reader()->read([self::HEADER, ['comp-01', '2027-01-15', '2027-01-16', $text, '', '', '', '', '', '']])->rows, $text);
        }
    }

    public function testAnOldCaptureIsImportedWithAWarning(): void
    {
        $result = self::reader()->read([self::HEADER, ['comp-01', '2027-01-15', '2027-01-16', '400', '', '', '', '2026-09-01', '', '']]);

        self::assertCount(1, $result->rows);
        self::assertSame([MarketCsvProblem::CAPTURE_TOO_OLD], array_map(static fn (MarketCsvProblem $p): string => $p->code, $result->ofKind(MarketCsvProblem::WARNING)));
    }

    public function testTheLaterOfTwoDuplicateRowsWins(): void
    {
        $result = self::reader()->read([
            self::HEADER,
            ['comp-01', '2027-01-15', '2027-01-16', '400', '', '', '', '2026-11-12 09:30', '', ''],
            ['comp-01', '2027-01-15', '2027-01-16', '410', '', '', '', '2026-11-12 09:30:40', '', ''],
        ]);

        self::assertCount(1, $result->rows);
        self::assertSame(410.0, $result->rows[0]->price);
        $warning = $result->ofKind(MarketCsvProblem::WARNING)[0];
        self::assertSame([MarketCsvProblem::DUPLICATE_IN_FILE, 2], [$warning->code, $warning->line]);
    }

    public function testGuestLikeAndUnknownColumnsAreIgnoredAndReported(): void
    {
        $result = self::reader()->read([
            ['competitor', 'check_in', 'check_out', 'price', 'Email', 'guest', 'notes'],
            ['comp-01', '2027-01-15', '2027-01-16', '400', 'someone@example.com', 'GUESTDATA', 'x'],
        ]);

        self::assertCount(1, $result->rows);
        self::assertSame([[MarketCsvProblem::GUEST_COLUMN, 'email'], [MarketCsvProblem::GUEST_COLUMN, 'guest'], [MarketCsvProblem::UNKNOWN_COLUMN, 'notes'], [MarketCsvProblem::CAPTURE_MISSING, 'captured_at']], array_map(static fn (MarketCsvProblem $p): array => [$p->code, $p->column], $result->ofKind(MarketCsvProblem::WARNING)));
        self::assertStringNotContainsString('GUESTDATA', serialize($result->rows[0]->toArray()));
        self::assertStringNotContainsString('example.com', serialize($result->rows[0]->toArray()));
    }

    public function testFileErrorsImportNothing(): void
    {
        self::assertSame([MarketCsvProblem::EMPTY_FILE], self::codes(self::reader()->read([[null], []])->ofKind(MarketCsvProblem::FILE)));
        self::assertSame([MarketCsvProblem::MISSING_COLUMN, MarketCsvProblem::MISSING_COLUMN], self::codes(self::reader()->read([['competitor', 'check_in'], ['comp-01', '2027-01-15']])->ofKind(MarketCsvProblem::FILE)));
        $tooMany = [self::HEADER];
        for ($i = 0; $i < 4; ++$i) {
            $tooMany[] = ['comp-01', '2027-01-15', '2027-01-16', '400', '', '', '', '', '', ''];
        }
        $result = self::reader(3)->read($tooMany);
        self::assertSame([MarketCsvProblem::TOO_MANY_ROWS], self::codes($result->ofKind(MarketCsvProblem::FILE)));
        self::assertSame([], $result->rows);
        self::assertSame([MarketCsvProblem::TOO_MANY_COLUMNS], self::codes(self::reader()->read([array_fill(0, 51, 'x')])->problems));
    }

    public function testBlankLinesAreSkippedAndKeepTheLineNumbers(): void
    {
        $result = self::reader()->read([self::HEADER, [null], ['comp-01', '2027-01-15', '2027-01-16', 'x', '', '', '', '', '', '']]);

        self::assertSame(3, $result->problems[0]->line);
        self::assertSame(1, $result->total);
    }

    public function testTheDedupKeyIgnoresSecondsOfTheCapture(): void
    {
        $a = ParsedRow::dedupKey('p', 1, Days::date('2027-01-15'), 2, RoomBasis::Cheapest, 'csv', new DateTimeImmutable('2027-01-02T09:30:05Z'));
        $b = ParsedRow::dedupKey('p', 1, Days::date('2027-01-15'), 2, RoomBasis::Cheapest, 'csv', new DateTimeImmutable('2027-01-02T09:30:59Z'));
        $c = ParsedRow::dedupKey('p', 1, Days::date('2027-01-15'), 2, RoomBasis::Comparable, 'csv', new DateTimeImmutable('2027-01-02T09:30:59Z'));

        self::assertSame($a, $b);
        self::assertNotSame($a, $c);
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $a);
    }

    public function testAPreviewedRowReadsBackTheSame(): void
    {
        $row = self::reader()->read([self::HEADER, ['comp-01', '2027-01-15', '2027-01-17', '1840.00', '', '', '', '2026-11-12 09:30', '', '']])->rows[0];

        self::assertEquals($row, ParsedRow::fromArray($row->toArray()));
        self::assertSame(1840.0 / 2, $row->toSample()->perNight());
    }

    /**
     * @param list<MarketCsvProblem> $problems
     *
     * @return list<string>
     */
    private static function codes(array $problems): array
    {
        return array_map(static fn (MarketCsvProblem $p): string => $p->code, $problems);
    }

    private static function reader(int $maxRows = 20000): MarketCsvReader
    {
        $competitors = new CompetitorIndex([
            ['id' => 1, 'code' => 'comp-01', 'reference' => '', 'name' => 'Competitor 01'],
            ['id' => 2, 'code' => 'comp-02', 'reference' => '', 'name' => 'Competitor 02'],
            ['id' => 3, 'code' => 'comp-03', 'reference' => 'ref-3', 'name' => 'Competitor 03'],
        ]);

        return new MarketCsvReader(new CsvContext('inn-1', Days::date('2026-11-13'), self::now(), 'America/Sao_Paulo', 'BRL', $competitors, $maxRows));
    }

    private static function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-11-13T13:00:00Z');
    }
}
