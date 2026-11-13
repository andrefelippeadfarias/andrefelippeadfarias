<?php

declare(strict_types=1);

namespace Ritmo\Tests\Unit\Application\Market;

use PHPUnit\Framework\TestCase;
use Ritmo\Application\Market\MarketRefused;
use Ritmo\Application\Market\UploadedFile;
use Ritmo\Admin\Market\MarketText;
use Ritmo\Domain\Market\Csv\MarketCsvProblem;
use Ritmo\Domain\Support\Days;
use Ritmo\Infrastructure\Db\Repository\EditorEventRepository;

final class MarketImportServiceTest extends TestCase
{
    private const HEADER = ['competitor', 'check_in', 'check_out', 'price', 'price_basis', 'room', 'currency', 'captured_at', 'source', 'available'];

    public function testAPreviewKeepsOnlyTheValidRowsAndTheCommitStoresThem(): void
    {
        $world = new MarketWorld();
        $world->addCompetitors(10);
        $file = self::file(self::rows(10, invalid: 2));

        $preview = $world->importService->preview(MarketWorld::PROPERTY, $file, 7);

        self::assertSame([10, 12, 2], [$preview->valid, $preview->total, $preview->invalidLines]);
        self::assertSame(2, $preview->nights);
        self::assertSame(10, $preview->competitors);
        self::assertSame('2026-12-04', Days::key($preview->firstCheckIn));
        self::assertSame([[12, MarketCsvProblem::BAD_PRICE, 'abc'], [13, MarketCsvProblem::UNKNOWN_COMPETITOR, 'nobody']], array_map(static fn (MarketCsvProblem $p): array => [$p->line, $p->code, $p->value], $preview->problems));
        self::assertSame(['nobody'], $preview->unknownCompetitors);
        self::assertSame(0, $world->count('price_samples'), 'nothing is stored by a preview');
        self::assertStringNotContainsString('<?php', json_encode($world->previews->stored));

        $result = $world->importService->commit(MarketWorld::PROPERTY, $preview->token, 7);

        self::assertSame([10, 0, 0, 2], [$result['imported'], $result['replaced'], $result['skipped'], $result['nights']]);
        self::assertSame(10, $world->count('price_samples'));
        self::assertSame([], $world->previews->stored, 'the preview is single use');
        $import = $world->imports->latest(MarketWorld::PROPERTY)[0];
        self::assertSame([12, 10, 2, 7], [$import['rows_total'], $import['rows_imported'], $import['rows_invalid'], $import['user_id']]);
        $stored = (string) $world->db->selectValue('SELECT problems FROM wp_ritmo_market_imports');
        self::assertStringNotContainsString('abc', $stored, 'the import keeps line, column and code, never a cell');
        self::assertStringNotContainsString('nobody', $stored);
        self::assertSame(EditorEventRepository::MARKET_IMPORT, $world->events->latest(MarketWorld::PROPERTY, [EditorEventRepository::MARKET_IMPORT])[0]['kind']);
    }

    public function testACellThatLooksLikePersonalDataIsNeverKeptNorShown(): void
    {
        $world = new MarketWorld();
        $world->addCompetitors(2);
        $rows = [
            ...self::rows(2),
            ['comp-01', '2026-12-04', '2026-12-06', 'maria.silva@example.com', '', '', '', '', '', ''],
            ['comp-01', '2026-12-04', '2026-12-06', '+55 11 91234-5678', '', '', '', '', '', ''],
            ['comp-02', '2026-12-04', '2026-12-06', 'CPF 123.456.789-09', '', '', '', '', '', ''],
            ['ana.lima@example.com', '2026-12-04', '2026-12-06', '900', '', '', '', '', '', ''],
        ];

        $preview = $world->importService->preview(MarketWorld::PROPERTY, self::file($rows), 7);

        self::assertSame([4, 5, 6, 7], array_map(static fn (MarketCsvProblem $p): int => $p->line, $preview->problems));
        foreach ($preview->problems as $problem) {
            self::assertSame(MarketCsvProblem::HIDDEN_VALUE, $problem->value);
            self::assertStringContainsString('value hidden', MarketText::problem($problem));
        }
        self::assertSame([], $preview->unknownCompetitors, 'never offered as a competitor to create');
        $stored = (string) json_encode($world->previews->stored);
        foreach (['maria.silva', '91234', '123.456.789', 'ana.lima'] as $private) {
            self::assertStringNotContainsString($private, $stored);
        }
    }

    public function testTheSameFileIsRefusedAndTheSameRowsChangeNothing(): void
    {
        $world = new MarketWorld();
        $world->addCompetitors(10);
        $world->importService->commit(MarketWorld::PROPERTY, $world->importService->preview(MarketWorld::PROPERTY, self::file(self::rows(10)), 7)->token, 7);

        try {
            $world->importService->preview(MarketWorld::PROPERTY, self::file(self::rows(10)), 7);
            self::fail('the same file twice');
        } catch (MarketRefused $refused) {
            self::assertSame([MarketRefused::ALREADY_IMPORTED, ['date' => '2026-11-13']], [$refused->reason, $refused->params]);
        }

        $sameRows = self::file([...self::rows(10), ['comp-01', '2026-12-04', '2026-12-06', '1840', '', '', '', '2026-11-12 09:30', 'booking', '1']], 'other');
        $result = $world->importService->commit(MarketWorld::PROPERTY, $world->importService->preview(MarketWorld::PROPERTY, $sameRows, 7)->token, 7);

        self::assertSame([0, 10], [$result['imported'], $result['replaced']]);
        self::assertSame(10, $world->count('price_samples'));
    }

    public function testAPreviewBelongsToItsUserAndPropertyAndExpires(): void
    {
        $world = new MarketWorld();
        $world->addCompetitors(10);
        $token = $world->importService->preview(MarketWorld::PROPERTY, self::file(self::rows(10)), 7)->token;

        self::assertMatchesRegularExpression('/^ritmo_csv_7_[0-9a-f]{16}$/', $token);
        self::assertNull($world->importService->pending(MarketWorld::PROPERTY, $token, 8));
        self::assertNull($world->importService->pending('inn-2', $token, 7));
        foreach ([[MarketWorld::PROPERTY, $token, 8], [MarketWorld::PROPERTY, 'ritmo_csv_7_zz', 7]] as [$property, $t, $user]) {
            try {
                $world->importService->commit($property, $t, $user);
                self::fail('a foreign preview');
            } catch (MarketRefused $refused) {
                self::assertSame(MarketRefused::PREVIEW_EXPIRED, $refused->reason);
            }
        }
        self::assertNotNull($world->importService->pending(MarketWorld::PROPERTY, $token, 7), 'a refused commit by another user does not drop it');
        $world->importService->cancel(MarketWorld::PROPERTY, $token, 7);
        self::assertNull($world->importService->pending(MarketWorld::PROPERTY, $token, 7));
    }

    public function testAFileErrorImportsNothing(): void
    {
        $world = new MarketWorld();
        try {
            $world->importService->preview(MarketWorld::PROPERTY, self::file([['competitor', 'check_in', 'price']]), 7);
            self::fail('missing column');
        } catch (MarketRefused $refused) {
            self::assertSame([MarketRefused::FILE_ERROR, 'check_out', ['code' => MarketCsvProblem::MISSING_COLUMN]], [$refused->reason, $refused->field, $refused->params]);
        }
        self::assertSame([], $world->previews->stored);
    }

    public function testACompetitorArchivedBetweenPreviewAndCommitLosesItsRows(): void
    {
        $world = new MarketWorld();
        $ids = $world->addCompetitors(10);
        $token = $world->importService->preview(MarketWorld::PROPERTY, self::file(self::rows(10)), 7)->token;
        $world->competitorService->archive(MarketWorld::PROPERTY, $ids[0], 7);

        $result = $world->importService->commit(MarketWorld::PROPERTY, $token, 7);

        self::assertSame([9, 1], [$result['imported'], $result['skipped']]);
    }

    public function testATypedPriceIsReadByTheSameRules(): void
    {
        $world = new MarketWorld();
        $world->addCompetitors(1);

        self::assertSame(1, $world->importService->addOne(MarketWorld::PROPERTY, ['competitor' => 'comp-01', 'check_in' => '2026-12-04', 'check_out' => '2026-12-05', 'price' => '480'], 7));
        try {
            $world->importService->addOne(MarketWorld::PROPERTY, ['competitor' => 'comp-01', 'check_in' => '2026-12-04', 'check_out' => '2026-12-05', 'price' => '-1'], 7);
            self::fail('a negative price');
        } catch (MarketRefused $refused) {
            self::assertSame([MarketRefused::BAD_PRICE, 'price', ['code' => MarketCsvProblem::BAD_PRICE]], [$refused->reason, $refused->field, $refused->params]);
        }
        self::assertSame('manual', $world->db->selectValue('SELECT source FROM wp_ritmo_price_samples'));
    }

    /**
     * Rows of comp-01..comp-NN for the stay 4-6 Dec, plus invalid ones.
     *
     * @return list<list<string>>
     */
    private static function rows(int $valid, int $invalid = 0): array
    {
        $rows = [];
        for ($i = 1; $i <= $valid; ++$i) {
            $rows[] = [sprintf('comp-%02d', $i), '2026-12-04', '2026-12-06', (string) (1500 + 20 * $i), 'total', 'cheapest', 'BRL', '2026-11-12 09:30', 'booking', '1'];
        }
        if ($invalid > 0) {
            $rows[] = ['comp-01', '2026-12-04', '2026-12-06', 'abc', '', '', '', '', '', ''];
            $rows[] = ['nobody', '2026-12-04', '2026-12-06', '900', '', '', '', '', '', ''];
        }

        return $rows;
    }

    /**
     * @param list<list<string>> $rows
     */
    private static function file(array $rows, string $salt = ''): UploadedFile
    {
        $records = $rows !== [] && $rows[0] === ['competitor', 'check_in', 'price'] ? $rows : [self::HEADER, ...$rows];

        return new UploadedFile('prices.csv', hash('sha256', json_encode($records) . $salt), 1234, $records);
    }
}
