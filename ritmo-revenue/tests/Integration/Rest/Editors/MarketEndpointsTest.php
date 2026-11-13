<?php

declare(strict_types=1);

namespace Ritmo\Tests\Integration\Rest\Editors;

require_once dirname(__DIR__, 3) . '/Unit/Admin/Editors/stubs.php';
require_once __DIR__ . '/stubs.php';

use PHPUnit\Framework\TestCase;
use Ritmo\Application\Market\MarketRefused;
use Ritmo\Application\Market\UploadedFile;
use Ritmo\Domain\Market\Csv\MarketCsvProblem;
use Ritmo\Domain\Support\Days;
use Ritmo\Infrastructure\Db\Repository\PropertyRepository;
use Ritmo\Rest\Market\MarketEndpoints;
use Ritmo\Tests\Unit\Application\Market\MarketWorld;

/**
 * The answers of the market REST routes over a real database (SQLite, every migration up to the
 * market draft): status codes, translated sentences, the two steps of an import, and nothing
 * internal in a body (no exception message, no cell beyond the 40 characters shown, no file).
 */
final class MarketEndpointsTest extends TestCase
{
    private const HEADER = ['competitor', 'check_in', 'check_out', 'price', 'price_basis', 'room', 'currency', 'captured_at', 'source', 'available'];

    private MarketWorld $world;

    protected function setUp(): void
    {
        $this->world = new MarketWorld();
    }

    public function testCompetitorsAreListedWithTheirFactorInWordsAndAnUnknownPropertyIsNotFound(): void
    {
        $this->world->addCompetitors(2, peers: 1);

        [$status, $body] = $this->endpoints()->competitors(MarketWorld::PROPERTY);

        self::assertSame(200, $status);
        self::assertSame(['comp-01', 'comp-02'], array_column($body['competitors'], 'code'));
        self::assertSame(['peer'], $body['competitors'][0]['tags']);
        self::assertSame('× 1 (not set: default)', $body['competitors'][0]['factor_text']);
        self::assertSame(0, $body['competitors'][0]['prices_last_30_days']);
        self::assertSame([404, ['code' => 'ritmo_not_found', 'message' => 'Not found.']], $this->endpoints()->competitors('nowhere'));
    }

    public function testACompetitorIsCreatedChangedInPartAndArchived(): void
    {
        [$status, $body] = $this->endpoints()->saveCompetitor(MarketWorld::PROPERTY, null, ['name' => 'Hill Lodge', 'factor' => '1.4', 'factor_source' => 'measured'], 7);
        self::assertSame(201, $status);
        $id = $body['id'];

        [$status] = $this->endpoints()->saveCompetitor(MarketWorld::PROPERTY, $id, ['rating' => '8.5'], 7);
        self::assertSame(200, $status);
        $row = $this->competitor($id);
        self::assertSame(['Hill Lodge', 'hill-lodge', 8.5, '× 1.4 (measured)', true], [$row['name'], $row['code'], $row['rating'], $row['factor_text'], $row['active']], 'a PUT keeps the fields it does not send');

        [$status, $body] = $this->endpoints()->saveCompetitor(MarketWorld::PROPERTY, $id, ['active' => false], 7);
        self::assertSame([200, 'Competitor archived: it no longer counts; its prices are kept.'], [$status, $body['text']]);
        self::assertFalse($this->competitor($id)['active']);
    }

    public function testRefusalsAreSentencesWithTheirStatusAndField(): void
    {
        [$status, $body] = $this->endpoints()->saveCompetitor(MarketWorld::PROPERTY, null, ['name' => 'Call 11 98765-4321'], 7);
        self::assertSame(400, $status);
        self::assertSame(['ritmo_privacy', 'name'], [$body['code'], $body['field']]);
        self::assertStringStartsWith('Do not write guest names', $body['message']);

        [$status, $body] = $this->endpoints()->saveCompetitor(MarketWorld::PROPERTY, 999, ['rating' => '5'], 7);
        self::assertSame([404, 'ritmo_competitor_unknown', 'That competitor does not exist.'], [$status, $body['code'], $body['message']]);

        [$status, $body] = $this->endpoints()->saveCompetitor('nowhere', null, ['name' => 'Hill Lodge'], 7);
        self::assertSame([404, 'ritmo_property_unknown'], [$status, $body['code']]);
        self::assertStringNotContainsString('Market action refused', json_encode($body), 'never the exception message');
    }

    public function testAPreviewListsBadLinesAndTheCommitStoresTheValidRowsOnce(): void
    {
        $this->world->addCompetitors(10);
        [$status, $body] = $this->endpoints()->preview(MarketWorld::PROPERTY, fn (): UploadedFile => self::file(self::rows(10, true)), 7, 2097152);

        self::assertSame(200, $status);
        self::assertSame([10, 12, 2, 2, 10], [$body['valid_rows'], $body['lines'], $body['invalid_lines'], $body['nights'], $body['competitors']]);
        self::assertSame(['2026-12-04', '2026-12-04'], [$body['first_check_in'], $body['last_check_in']]);
        self::assertSame('10 valid rows for 2 nights and 10 competitors; 2 lines ignored. Nothing is stored until you confirm.', $body['text']);
        self::assertSame([12, 'price', MarketCsvProblem::ERROR], [$body['problems'][0]['line'], $body['problems'][0]['column'], $body['problems'][0]['kind']]);
        self::assertSame('Line 12, column price: the price is not a number above zero (such as 480 or 480.50, without thousands separators). ("abc")', $body['problems'][0]['text']);
        self::assertSame(['nobody'], $body['unknown_competitors']);
        self::assertSame(0, $this->world->count('price_samples'), 'a preview stores nothing');

        [$status, $result] = $this->endpoints()->commit(MarketWorld::PROPERTY, $body['token'], 7);
        self::assertSame(200, $status);
        self::assertSame([10, 0, 2], [$result['imported'], $result['replaced'], $result['nights']]);
        self::assertSame('10 prices recorded; market values updated for 2 nights.', $result['text']);
        self::assertSame(10, $this->world->count('price_samples'));

        [$status, $again] = $this->endpoints()->commit(MarketWorld::PROPERTY, $body['token'], 7);
        self::assertSame([409, 'ritmo_preview_expired'], [$status, $again['code']], 'a preview is used once');
    }

    public function testAPreviewOfAnotherUserCannotBeCommitted(): void
    {
        $this->world->addCompetitors(3);
        [, $body] = $this->endpoints()->preview(MarketWorld::PROPERTY, fn (): UploadedFile => self::file(self::rows(3)), 7, 2097152);

        [$status, $refused] = $this->endpoints()->commit(MarketWorld::PROPERTY, $body['token'], 8);

        self::assertSame([409, 'ritmo_preview_expired'], [$status, $refused['code']]);
        self::assertSame(0, $this->world->count('price_samples'));
    }

    public function testFileErrorsAndARepeatedFileAreRefusedBeforeAnythingIsStored(): void
    {
        $this->world->addCompetitors(3);
        $tooLarge = static fn (): UploadedFile => throw new MarketRefused(MarketRefused::FILE_ERROR, '', ['code' => MarketCsvProblem::FILE_TOO_LARGE]);

        [$status, $body] = $this->endpoints()->preview(MarketWorld::PROPERTY, $tooLarge, 7, 2097152);
        self::assertSame([400, 'ritmo_file_error', 'The file is larger than 2048 KB. Nothing was imported.'], [$status, $body['code'], $body['message']]);

        [$status, $body] = $this->endpoints()->preview(MarketWorld::PROPERTY, fn (): UploadedFile => self::file([['competitor', 'check_in', 'price'], ['comp-01', '2026-12-04', '100']]), 7, 2097152);
        self::assertSame([400, 'Column check_out: this required column is missing. Nothing was imported.'], [$status, $body['message']]);

        [, $first] = $this->endpoints()->preview(MarketWorld::PROPERTY, fn (): UploadedFile => self::file(self::rows(3)), 7, 2097152);
        $this->endpoints()->commit(MarketWorld::PROPERTY, $first['token'], 7);
        [$status, $body] = $this->endpoints()->preview(MarketWorld::PROPERTY, fn (): UploadedFile => self::file(self::rows(3)), 7, 2097152);
        self::assertSame([409, 'ritmo_already_imported', 'This file was already imported on 2026-11-13. Nothing was imported.'], [$status, $body['code'], $body['message']]);
        self::assertSame(3, $this->world->count('price_samples'));

        self::assertSame(404, $this->endpoints()->preview('nowhere', fn (): UploadedFile => self::file(self::rows(3)), 7, 2097152)[0]);
    }

    public function testTheRulerAnswersTheNightsAskedForWithinItsLimit(): void
    {
        $this->world->addCompetitors(12);
        [, $preview] = $this->endpoints()->preview(MarketWorld::PROPERTY, fn (): UploadedFile => self::file(self::rows(12)), 7, 2097152);
        $this->endpoints()->commit(MarketWorld::PROPERTY, $preview['token'], 7);

        [$status, $body] = $this->endpoints()->ruler(MarketWorld::PROPERTY, null, null);
        self::assertSame(200, $status);
        self::assertSame('BRL', $body['currency']);
        self::assertCount(90, $body['nights']);
        self::assertSame('2026-11-13', $body['nights'][0]['night'], 'from today at the property');

        [, $body] = $this->endpoints()->ruler(MarketWorld::PROPERTY, Days::date('2026-12-04'), Days::date('2026-12-05'));
        $night = $body['nights'][0];
        self::assertSame('2026-12-04', $night['night']);
        self::assertArrayHasKey('cheapest', $night['series']);
        self::assertSame('containing', $night['series']['cheapest']['origin'], '12 competitors are fewer than the 20 a night of its own needs');
        self::assertSame('stays that include the night', $night['series']['cheapest']['origin_text']);
        self::assertSame(12, $night['series']['cheapest']['competitors']);
        self::assertNotNull($night['market'], 'the import also feeds the market anchor');

        [$status, $body] = $this->endpoints()->ruler(MarketWorld::PROPERTY, Days::date('2026-12-05'), Days::date('2026-12-04'));
        self::assertSame([400, 'ritmo_bad_range'], [$status, $body['code']]);
        self::assertSame(400, $this->endpoints()->ruler(MarketWorld::PROPERTY, Days::date('2026-12-01'), Days::date('2027-04-01'))[0]);
        self::assertSame(404, $this->endpoints()->ruler('nowhere', null, null)[0]);
    }

    public function testImportsListCountsAndNeverTheFile(): void
    {
        $this->world->addCompetitors(10);
        [, $preview] = $this->endpoints()->preview(MarketWorld::PROPERTY, fn (): UploadedFile => self::file(self::rows(10, true)), 7, 2097152);
        $this->endpoints()->commit(MarketWorld::PROPERTY, $preview['token'], 7);

        [$status, $body] = $this->endpoints()->imports(MarketWorld::PROPERTY);

        self::assertSame(200, $status);
        self::assertCount(1, $body['imports']);
        self::assertSame(['prices.csv', 12, 10, 0, 2], [$body['imports'][0]['file_name'], $body['imports'][0]['rows_total'], $body['imports'][0]['rows_imported'], $body['imports'][0]['rows_replaced'], $body['imports'][0]['rows_invalid']]);
        $text = (string) json_encode($body);
        self::assertStringNotContainsString('abc', $text);
        self::assertStringNotContainsString('nobody', $text);
        self::assertSame(404, $this->endpoints()->imports('nowhere')[0]);
    }

    private function endpoints(): MarketEndpoints
    {
        return new MarketEndpoints(
            new PropertyRepository($this->world->db, $this->world->clock),
            $this->world->competitorService,
            $this->world->board,
            $this->world->importService,
            fn () => $this->world->ruleSets->current(),
            $this->world->clock,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function competitor(int $id): array
    {
        foreach ($this->endpoints()->competitors(MarketWorld::PROPERTY)[1]['competitors'] as $row) {
            if ($row['id'] === $id) {
                return $row;
            }
        }
        self::fail('competitor ' . $id . ' is listed');
    }

    /**
     * Rows of comp-01..comp-NN for the stay 4-6 Dec, and with $invalid two bad lines.
     *
     * @return list<list<string>>
     */
    private static function rows(int $valid, bool $invalid = false): array
    {
        $rows = [];
        for ($i = 1; $i <= $valid; ++$i) {
            $rows[] = [sprintf('comp-%02d', $i), '2026-12-04', '2026-12-06', (string) (1500 + 20 * $i), 'total', 'cheapest', 'BRL', '2026-11-12 09:30', 'booking', '1'];
        }
        if ($invalid) {
            $rows[] = ['comp-01', '2026-12-04', '2026-12-06', 'abc', '', '', '', '', '', ''];
            $rows[] = ['nobody', '2026-12-04', '2026-12-06', '900', '', '', '', '', '', ''];
        }

        return $rows;
    }

    /**
     * @param list<list<string>> $rows
     */
    private static function file(array $rows): UploadedFile
    {
        $records = $rows !== [] && $rows[0][0] === 'competitor' ? $rows : [self::HEADER, ...$rows];

        return new UploadedFile('prices.csv', hash('sha256', (string) json_encode($records)), 1234, $records);
    }
}
