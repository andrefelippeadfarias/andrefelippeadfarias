<?php

declare(strict_types=1);

namespace Ritmo\Tests\Unit\Application\Market;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Ritmo\Application\Market\MarketFreshness;
use Ritmo\Application\Market\MarketRefused;
use Ritmo\Application\Market\UploadedFile;
use Ritmo\Domain\Config\RuleSet;
use Ritmo\Admin\Components\CsvWriter;
use Ritmo\Domain\Market\Series\MarketConfig;
use Ritmo\Domain\Market\Series\NightValue;
use Ritmo\Domain\Model\Property;
use Ritmo\Domain\Support\Days;

final class MarketSeriesServiceTest extends TestCase
{
    public function testAnImportBuildsTheSeriesTheAnchorAndTheRuler(): void
    {
        $world = new MarketWorld();
        $world->addCompetitors(12, 4);
        $world->competitorService->save(MarketWorld::PROPERTY, null, ['name' => 'Our own listing', 'code' => 'own', 'is_own' => true], 7);
        $rows = [];
        for ($i = 1; $i <= 12; ++$i) {
            $rows[] = [sprintf('comp-%02d', $i), '2026-12-04', '2026-12-06', (string) (800 + 40 * $i), 'total', 'cheapest', '', '2026-11-12 09:30', '', ''];
        }
        $rows[] = ['own', '2026-12-04', '2026-12-06', '100', 'total', 'cheapest', '', '2026-11-12 09:30', '', ''];
        $world->importService->commit(MarketWorld::PROPERTY, $world->importService->preview(MarketWorld::PROPERTY, self::file($rows), 7)->token, 7);

        $values = $world->series->range(MarketWorld::PROPERTY, Days::date('2026-12-04'), Days::date('2026-12-05'));
        $friday = $values['2026-12-04'];
        self::assertSame(['cheapest', 'comparable'], array_keys($friday), 'peers have 4 competitors, below the 10 a stay needs');
        self::assertSame(NightValue::CONTAINING, $friday['cheapest']->origin, 'the stay has 12 values, below the 20 a night alone needs');
        self::assertSame(12, $friday['cheapest']->competitors);
        self::assertSame(530, $friday['cheapest']->values['50'], 'percentile 50 of 420..640 per night: the own listing (50) left out');
        self::assertSame($friday['cheapest']->values, $friday['comparable']->values, 'the neutral comparable factor is 1.0');
        self::assertSame('2026-12-05', array_key_last($values));
        self::assertArrayNotHasKey('2026-12-08', $world->series->range(MarketWorld::PROPERTY, Days::date('2026-12-08'), Days::date('2026-12-08')), 'a weekday has no stay of its kind');
        self::assertSame(NightValue::TYPE_TABLE, $world->series->range(MarketWorld::PROPERTY, Days::date('2026-12-18'), Days::date('2026-12-18'))['2026-12-18']['cheapest']->origin, 'a later Friday takes the typical weekend value');

        $anchor = $world->anchors->range(MarketWorld::PROPERTY, Days::date('2026-12-04'), Days::date('2026-12-05'))['2026-12-04'];
        self::assertSame(12, $anchor->n);

        $ruler = $world->ruler->of(new Property(MarketWorld::PROPERTY, 'Inn One', 'America/Sao_Paulo', 'BRL'), RuleSet::defaults(), Days::date('2026-12-03'), Days::date('2026-12-06'));
        self::assertNull($ruler->night(Days::date('2026-12-03')));
        $night = $ruler->night(Days::date('2026-12-04'));
        self::assertNotNull($night);
        self::assertSame('weekend', $night->type);
        self::assertSame(530.0, $night->valueAt('comparable', 50));
        self::assertNotNull($night->valueAt('market', 50));
        self::assertNull($night->valueAt('comparable', 55), 'the ruler is exact');
        self::assertSame(1.0, $night->publicFactor(MarketWorld::PROPERTY));
    }

    public function testTheCacheIsRebuiltOnlyWhenItsInputsChanged(): void
    {
        $world = new MarketWorld();
        $world->addCompetitors(1);

        self::assertFalse($world->seriesService->rebuildIfStale(MarketWorld::PROPERTY));
        $world->importService->addOne(MarketWorld::PROPERTY, ['competitor' => 'comp-01', 'check_in' => '2026-12-04', 'check_out' => '2026-12-05', 'price' => '480'], 7);
        self::assertFalse($world->seriesService->rebuildIfStale(MarketWorld::PROPERTY), 'the typed price rebuilt it already');
        $world->clock->advance(86400);
        self::assertTrue($world->seriesService->rebuildIfStale(MarketWorld::PROPERTY), 'another day');
        self::assertFalse($world->seriesService->rebuildIfStale('nowhere'));
        $this->expectException(MarketRefused::class);
        $world->seriesService->rebuild('nowhere');
    }

    public function testFreshnessFollowsTheNewestPriceOfTheComingNights(): void
    {
        $world = new MarketWorld();
        $world->addCompetitors(1);
        $freshness = new MarketFreshness($world->samples);
        $config = MarketConfig::neutral();
        $today = Days::date('2026-11-13');

        self::assertSame(['stale' => true, 'newest' => null, 'age_days' => null], $freshness->check(MarketWorld::PROPERTY, $today, 'America/Sao_Paulo', $config));
        $world->importService->commit(MarketWorld::PROPERTY, $world->importService->preview(MarketWorld::PROPERTY, self::file([['comp-01', '2026-12-04', '2026-12-05', '480', '', '', '', '2026-11-04 10:00', '', '']]), 7)->token, 7);
        self::assertSame([true, 9], array_values(array_slice($freshness->check(MarketWorld::PROPERTY, $today, 'America/Sao_Paulo', $config), 0, 1) + ['age' => $freshness->check(MarketWorld::PROPERTY, $today, 'America/Sao_Paulo', $config)['age_days']]));
        $world->importService->addOne(MarketWorld::PROPERTY, ['competitor' => 'comp-01', 'check_in' => '2026-12-11', 'check_out' => '2026-12-12', 'price' => '480'], 7);
        $fresh = $freshness->check(MarketWorld::PROPERTY, $today, 'America/Sao_Paulo', $config);
        self::assertSame([false, 0], [$fresh['stale'], $fresh['age_days']]);
        self::assertTrue($freshness->check(MarketWorld::PROPERTY, Days::date('2027-02-01'), 'America/Sao_Paulo', $config)['stale'], 'no price for the nights ahead of that day');
    }

    public function testExportsCarryTheDocumentedColumnsAndTheFormulaGuard(): void
    {
        $world = new MarketWorld();
        $world->competitorService->save(MarketWorld::PROPERTY, null, ['name' => '=HYPERLINK("x")', 'code' => 'comp-x'], 7);
        $world->importService->addOne(MarketWorld::PROPERTY, ['competitor' => 'comp-x', 'check_in' => '2026-12-04', 'check_out' => '2026-12-06', 'price' => '960'], 7);

        $competitors = iterator_to_array($world->export->competitors(MarketWorld::PROPERTY), false);
        self::assertSame("'=HYPERLINK(\"x\")", CsvWriter::cell($competitors[0][1]));
        $samples = iterator_to_array($world->export->samples(MarketWorld::PROPERTY, []), false);
        self::assertSame(['comp-x', '2026-12-04', '2026-12-06', 'cheapest', 480.0, 'total', 960.0, 'BRL', '2026-11-13T13:00:00Z', 'manual', 1], $samples[0]);
        self::assertSame(['comp-x', '2026-12-04', '2026-12-06', 'cheapest', '480', 'total', '960', 'BRL', '2026-11-13T13:00:00Z', 'manual', '1'], array_map(CsvWriter::cell(...), $samples[0]));
        $ruler = iterator_to_array($world->export->ruler(MarketWorld::PROPERTY, 'BRL', Days::date('2026-12-01'), Days::date('2026-12-31'), static fn (DateTimeImmutable $n): string => 'weekend'), false);
        self::assertSame(['market'], array_values(array_unique(array_column($ruler, 2))), 'one competitor is below the 10 a stay needs: only the market anchor');
        self::assertCount(10, $ruler, 'two nights of the stay, five anchor percentiles each');
        self::assertSame(['2026-12-04', 'weekend', 'market', 25, 480.0, 'BRL', 1, null, 'measured', null, '2026-11-13', '2026-11-13'], $ruler[0]);
    }

    public function testTheBoardListsNightsCompetitorsPricesAndImports(): void
    {
        $world = new MarketWorld();
        $world->addCompetitors(2);
        $world->importService->addOne(MarketWorld::PROPERTY, ['competitor' => 'comp-02', 'check_in' => '2026-12-04', 'check_out' => '2026-12-05', 'price' => '480'], 7);

        $nights = $world->board->nights(new Property(MarketWorld::PROPERTY, 'Inn One', 'America/Sao_Paulo', 'BRL'), RuleSet::defaults(), MarketConfig::neutral(), Days::date('2026-12-01'), 7);
        self::assertCount(7, $nights);
        self::assertSame(['weekday', []], [$nights[0]['type'], $nights[0]['series']]);
        $competitors = $world->board->competitors(MarketWorld::PROPERTY);
        self::assertSame([0, 1], array_column($competitors, 'prices_30_days'));
        self::assertSame(1, $world->board->prices(MarketWorld::PROPERTY, ['competitor' => $competitors[1]['id']], 1)['total']);
        self::assertSame(0, $world->board->prices(MarketWorld::PROPERTY, ['source' => 'csv'], 1)['total']);
        self::assertSame([], $world->board->imports(MarketWorld::PROPERTY));
    }

    /**
     * @param list<list<string>> $rows
     */
    private static function file(array $rows): UploadedFile
    {
        $records = [['competitor', 'check_in', 'check_out', 'price', 'price_basis', 'room', 'currency', 'captured_at', 'source', 'available'], ...$rows];

        return new UploadedFile('prices.csv', hash('sha256', json_encode($records)), 100, $records);
    }
}
