<?php

declare(strict_types=1);

namespace Ritmo\Tests\Unit\Domain\Market\Series;

use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Ritmo\Domain\Config\InvalidConfig;
use Ritmo\Domain\Config\Schema;
use Ritmo\Domain\Config\ValidationError;
use Ritmo\Domain\Market\Series\ComparableFactor;
use Ritmo\Domain\Market\Series\CompetitorProfile;
use Ritmo\Domain\Market\Series\LadderSeriesCheck;
use Ritmo\Domain\Market\Series\MarketConfig;
use Ritmo\Domain\Market\Series\MarketFields;
use Ritmo\Domain\Market\Series\NewestSamples;
use Ritmo\Domain\Market\Series\NightSeries;
use Ritmo\Domain\Market\Series\Percentile;
use Ritmo\Domain\Market\Series\RoomBasis;
use Ritmo\Domain\Market\Series\SampleWindow;
use Ritmo\Domain\Market\Series\SeriesBuilder;
use Ritmo\Domain\Market\Series\SeriesDefinition;
use Ritmo\Domain\Market\Series\StaySample;
use Ritmo\Domain\Market\Series\StayValues;
use Ritmo\Domain\Support\Days;
use Ritmo\Tests\Support\Fixtures;

final class SeriesRulesTest extends TestCase
{
    public function testTheNeutralMarketSectionIsTheDocumentedOne(): void
    {
        $config = MarketConfig::neutral();

        self::assertSame(['cheapest', 'comparable', 'peers_cheapest', 'peers_comparable'], array_keys($config->seriesByName()));
        self::assertSame(['peer'], $config->seriesByName()['peers_comparable']->tags);
        self::assertSame([10, 25, 40, 50, 65, 75, 90], $config->percentiles);
        self::assertSame([10, 20, 3, 30, 400, 7, 60], [$config->minCompetitorsPerStay, $config->nightMinCompetitors, $config->neighbourDays, $config->maxSampleAgeDays, $config->retentionDays, $config->staleAfterDays, $config->reminderHorizonDays]);
        self::assertSame([1.0, 2.0], [$config->defaultFactor, $config->estimatedFactorCap]);
        self::assertSame([2097152, 20000, 7], [$config->csvMaxBytes, $config->csvMaxRows, $config->csvMaxStayNights]);
        self::assertSame(['Fill', 'Market', 'Above market', 'Premium', 'Top'], array_column($config->bands, 'label'));
        self::assertSame(MarketConfig::neutral()->percentiles, (new Schema(MarketFields::all()))->defaults()['market']['percentiles']);
        foreach (MarketFields::all() as $field) {
            self::assertStringStartsWith('market', $field->key);
        }
    }

    public function testTheMarketSectionRefusesWhatTheRulerCannotBuild(): void
    {
        $codes = static function (array $data): array {
            try {
                MarketConfig::fromArray($data);
            } catch (InvalidConfig $e) {
                return array_map(static fn (ValidationError $v): string => $v->path . ' ' . $v->code, $e->errors());
            }

            return [];
        };

        self::assertSame(['market.percentiles order'], $codes(['percentiles' => [50, 25], 'bands' => []]));
        self::assertSame(['market.bands.0.percentile reference'], $codes(['bands' => [['label' => 'Middle', 'percentile' => 55]]]));
        self::assertSame(['market.series.1.name unique'], $codes(['series' => [['name' => 'cheapest', 'basis' => 'cheapest', 'tags' => []], ['name' => 'cheapest', 'basis' => 'comparable', 'tags' => []]]]));
        self::assertSame(['market.series.0.name format'], $codes(['series' => [['name' => 'market', 'basis' => 'cheapest', 'tags' => []]]]));
        self::assertContains('market.min_competitors_per_stay range', $codes(['min_competitors_per_stay' => 0]));
        self::assertContains('market.surprise unknown', $codes(['surprise' => 1]));
    }

    public function testALadderRuleMayCiteOnlyBuiltSeriesAndKeptPercentiles(): void
    {
        $ladder = ['strategies' => [[
            'rules' => [
                ['opening' => ['series' => 'comparable', 'percentile' => 40.0], 'ceiling' => ['series' => 'market', 'percentile' => 90.0]],
                ['opening' => ['series' => 'peers_comparable', 'percentile' => 55.0], 'ceiling' => ['series' => null, 'percentile' => 50.0]],
                ['opening' => ['series' => 'nearby', 'percentile' => 50.0], 'ceiling' => ['series' => 'market', 'percentile' => 40.0]],
            ],
            'last_minute_floor' => ['series' => 'cheapest', 'percentile' => 10],
        ]]];

        $errors = LadderSeriesCheck::errors($ladder, MarketConfig::neutral());

        self::assertSame([
            'ladder.strategies.0.rules.1.opening.percentile ' . LadderSeriesCheck::PERCENTILE_NOT_MEASURED,
            'ladder.strategies.0.rules.2.opening.series ' . LadderSeriesCheck::SERIES_UNKNOWN,
            'ladder.strategies.0.rules.2.ceiling.percentile ' . LadderSeriesCheck::PERCENTILE_NOT_MEASURED,
        ], array_map(static fn (ValidationError $e): string => $e->path . ' ' . $e->code, $errors));
    }

    public function testTheParityLadderCitesOnlyWhatTheNeutralMarketBuilds(): void
    {
        self::assertSame([], LadderSeriesCheck::errors(Fixtures::parityRules()->toArray()['ladder'], MarketConfig::neutral()));
    }

    public function testOnlyTheNewestCaptureOfACompetitorsStayCountsAndTiesGoToTheLaterLine(): void
    {
        $old = self::sample(1, 400.0, '2026-11-01T10:00:00Z', 1);
        $new = self::sample(1, 450.0, '2026-11-05T10:00:00Z', 2);
        $tieEarly = self::sample(2, 300.0, '2026-11-05T10:00:00Z', 5);
        $tieLate = self::sample(2, 320.0, '2026-11-05T10:00:00Z', 9);

        self::assertSame([$new, $tieLate], NewestSamples::of([$old, $new, $tieLate, $tieEarly]));
    }

    public function testAMeasuredComparableRowBeatsTheDerivedValue(): void
    {
        $catalogue = [1 => new CompetitorProfile(1, 'comp-a', [], 1.5, ComparableFactor::MEASURED), 2 => new CompetitorProfile(2, 'comp-b')];
        $samples = [
            self::sample(1, 400.0), self::sample(1, 700.0, basis: RoomBasis::Comparable),
            self::sample(2, 300.0),
        ];

        self::assertSame([700.0, 300.0 * 1.2], StayValues::of($samples, $catalogue, new SeriesDefinition('comparable', RoomBasis::Comparable), 1.2, 2.0));
        self::assertSame([400.0, 300.0], StayValues::of($samples, $catalogue, new SeriesDefinition('cheapest', RoomBasis::Cheapest), 1.2, 2.0));
    }

    public function testOwnInactiveUnknownAndSoldOutRowsNeverCount(): void
    {
        $catalogue = [
            1 => new CompetitorProfile(1, 'own', [], null, '', true, true),
            2 => new CompetitorProfile(2, 'inactive', [], null, '', false),
            3 => new CompetitorProfile(3, 'peer', ['peer']),
            4 => new CompetitorProfile(4, 'other'),
        ];
        $samples = [self::sample(1, 100.0), self::sample(2, 200.0), self::sample(3, 300.0), self::sample(4, 400.0, available: false), self::sample(9, 900.0)];

        self::assertSame([300.0], StayValues::of($samples, $catalogue, new SeriesDefinition('cheapest', RoomBasis::Cheapest), 1.0, 2.0));
        self::assertSame([300.0], StayValues::of($samples, $catalogue, new SeriesDefinition('peers', RoomBasis::Cheapest, ['peer']), 1.0, 2.0));
    }

    public function testStartedStaysAndOldCapturesStayOutAndMixedStaysToo(): void
    {
        $catalogue = [];
        $samples = [];
        foreach (range(1, 3) as $id) {
            $catalogue[$id] = new CompetitorProfile($id, 'comp-' . $id);
            $samples[] = self::sample($id, 400.0 + $id, '2026-11-10T10:00:00Z', 0, '2026-12-04', 2);
            $samples[] = self::sample($id, 300.0, '2026-11-10T10:00:00Z', 0, '2026-11-12', 1);
            $samples[] = self::sample($id, 300.0, '2026-09-01T10:00:00Z', 0, '2026-12-10', 1);
            $samples[] = self::sample($id, 500.0, '2026-11-10T10:00:00Z', 0, '2026-12-11', 2);
        }
        $config = MarketConfig::fromArray(['min_competitors_per_stay' => 3]);
        $typeOf = static fn (DateTimeImmutable $checkIn, int $nights): ?string => Days::key($checkIn) === '2026-12-11' ? null : 'weekend';
        $window = new SampleWindow(Days::date('2026-11-13'), new DateTimeImmutable('2026-10-14T00:00:00Z'));

        $built = (new SeriesBuilder($config, $catalogue, $typeOf))->build($samples, $window);

        self::assertCount(1, $built['cheapest']);
        $stay = $built['cheapest'][0];
        self::assertSame(['2026-12-04', 3, 402], [Days::key($stay->checkIn), $stay->n, $stay->percentiles['50']]);
        self::assertSame([], $built['peers_cheapest'], 'no competitor carries the peer tag');
        self::assertSame('2026-11-10T10:00:00+00:00', $stay->newestCapture->format(DATE_ATOM));
    }

    public function testANightWithoutAnyStayOfItsKindHasNoValue(): void
    {
        self::assertNull((new NightSeries(20, 3))->of(Days::date('2026-12-01'), [], null));
    }

    public function testInvalidInputsAreRefused(): void
    {
        $refused = 0;
        foreach ([
            static fn () => Percentile::of([], 50),
            static fn () => Percentile::of([1.0], 101),
            static fn () => new CompetitorProfile(1, 'Bad Code'),
            static fn () => new CompetitorProfile(1, 'ok', ['Not A Tag']),
            static fn () => new CompetitorProfile(1, 'ok', [], 11.0),
            static fn () => new SeriesDefinition('market', RoomBasis::Cheapest),
            static fn () => self::sample(1, 0.0),
            static fn () => self::sample(1, 10.0, nights: 31),
        ] as $make) {
            try {
                $make();
            } catch (InvalidArgumentException) {
                ++$refused;
            }
        }
        self::assertSame(8, $refused);
    }

    private static function sample(int $competitor, float $price, string $captured = '2026-11-10T10:00:00Z', int $sequence = 0, string $checkIn = '2026-12-04', int $nights = 2, RoomBasis $basis = RoomBasis::Cheapest, bool $available = true): StaySample
    {
        return new StaySample($competitor, Days::date($checkIn), $nights, $basis, $price, null, new DateTimeImmutable($captured), $available, $sequence);
    }
}
