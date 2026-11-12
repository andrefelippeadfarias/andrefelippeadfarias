<?php

declare(strict_types=1);

namespace Ritmo\Tests\Unit\Domain\Relations\Golden;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Ritmo\Domain\Market\PriceFactor;
use Ritmo\Domain\Model\RoomType;
use Ritmo\Domain\Relations\Audit\PremiumAudit;
use Ritmo\Domain\Relations\Audit\RelationAuditor;
use Ritmo\Domain\Relations\Coherence\CoherenceContext;
use Ritmo\Domain\Relations\Coherence\CoherencePass;
use Ritmo\Domain\Relations\Coherence\DraftItem;
use Ritmo\Domain\Relations\Coherence\NightFacts;
use Ritmo\Domain\Relations\Coherence\NightSet;
use Ritmo\Domain\Relations\Coherence\Outcome;
use Ritmo\Domain\Relations\Coherence\PlanDraft;
use Ritmo\Domain\Relations\Coherence\RelationDecision;
use Ritmo\Domain\Relations\Coherence\RelationReport;
use Ritmo\Domain\Relations\Coherence\RoomNight;
use Ritmo\Domain\Relations\Config\RelationsConfig;
use Ritmo\Domain\Relations\ListingCapChain;
use Ritmo\Domain\Relations\Neutral\ConfiguredPriceBands;
use Ritmo\Domain\Relations\Params\SwitchParams;
use Ritmo\Domain\Relations\PremiumBounds;
use Ritmo\Domain\Relations\Relation;
use Ritmo\Domain\Relations\RelationGraph;
use Ritmo\Domain\Relations\RelationType;
use Ritmo\Domain\Relations\Validate\BandIssue;
use Ritmo\Domain\Relations\Validate\DiscountBandCheck;
use Ritmo\Domain\Relations\Validate\FreshNight;
use Ritmo\Domain\Relations\Validate\PlanNightValues;
use Ritmo\Domain\Support\DateRange;
use Ritmo\Domain\Support\Days;
use Ritmo\Tests\Support\Fixtures;

/**
 * Parity of the relations with the reference Python engine: every golden file of
 * tests/fixtures/golden/m2/relations, computed in PHP from the parity relations, the parity rooms
 * and the hook answers the generator recorded, must give the engine's result (amounts within
 * 0.01; flags, counts, rooms, nights and order ids exactly).
 *
 * The one deliberate divergence (a night held by an occupancy switch without a price item, see
 * docs/RELATIONS.md) is written in the case as "php_expected" and checked here.
 */
final class RelationsGoldenTest extends TestCase
{
    private const AMOUNT_TOLERANCE = 0.01;

    private const COVERAGE = [
        'pace.coerencia.json', 'ordem_recanto.faixa.json', 'ordem_recanto.faixa_afrodite.json', 'ordem_recanto.comparar.json',
        'pace.regra_afrodite.json', 'pace.teto_anuncio.json', 'pace.limitar_ao_maximo.json',
        'plano_dono.balcony_no_mercado.json', 'validar_plano.validar_balcony.json',
    ];

    public function testEveryGoldenFileIsCoveredAndTheInputsAreTheFrozenOnes(): void
    {
        $manifest = Fixtures::json(ParityRelations::GOLDEN . 'MANIFEST.json');
        $onDisk = array_map('basename', glob(Fixtures::path(ParityRelations::GOLDEN . '*.*.json')) ?: []);
        sort($onDisk);
        $covered = self::COVERAGE;
        sort($covered);
        self::assertSame($covered, $onDisk, 'every golden file of the relations needs a test here');
        self::assertSame($covered, array_keys($manifest['files']));
        self::assertFalse($manifest['engine_dirty'], 'goldens must come from committed engine code');
        foreach ($manifest['frozen_inputs'] as $path => $hash) {
            self::assertSame($hash, hash_file('sha256', Fixtures::pluginRoot() . '/' . self::pluginRelative($path)), $path . ' changed without regenerating');
        }
        self::assertSame($manifest['parity_preset'], hash_file('sha256', Fixtures::path('presets/parity-relations.json')));
        foreach ($manifest['files'] as $file => $count) {
            self::assertCount($count, Fixtures::json(ParityRelations::GOLDEN . $file)['cases'], $file);
        }
    }

    /**
     * @param array<string, mixed> $input
     * @param array<string, mixed> $expected
     */
    #[DataProvider('coherenceCases')]
    public function testCoherenceStage(array $input, array $expected): void
    {
        $graph = ParityRelations::graph($input);
        $config = ParityRelations::config($input['relations_patch'] ?? [], $input['scan_days']);
        $rooms = ParityRelations::rooms($input['rooms_patch'] ?? []);
        $facts = self::facts($input, $rooms);
        $today = Days::date($input['today']);
        $calendar = new ParityNightCalendar();
        $draft = new PlanDraft(array_map(self::draftItem(...), $input['draft']));
        $context = new CoherenceContext($graph, $config->settings, $facts, RecordedHooks::bundle($input, $calendar), $draft, new RelationReport(), $today);

        $nights = NightSet::of($graph, $facts, $today, $config->settings->scanHorizonDays);
        self::assertSame($input['nights'], array_map(Days::key(...), $nights), 'night set');
        foreach ($input['night_types'] as $night => $type) {
            self::assertSame($type, $calendar->nightType(Days::date($night))->value, 'night type of ' . $night);
        }
        (new CoherencePass($context))->run($nights);

        self::assertAmounts($expected['items'], array_map(static fn (DraftItem $item): array => $item->toArray(), $draft->items()), 'items');
        $decided = $facts->decidedPrices();
        ksort($decided);
        self::assertAmounts($expected['decided'], $decided, 'decided prices');
    }

    /**
     * @param array<string, mixed> $input
     * @param array<string, mixed> $expected
     */
    #[DataProvider('betweenAuditCases')]
    public function testAuditOfABetweenRelation(array $input, array $expected): void
    {
        $night = Days::date($input['night']);
        $finding = self::auditor($input, $input['today'] ?? $input['night'])
            ->auditBetween(self::relation('recanto-q2-between-q7-double'), $night);

        self::assertNotNull($finding);
        self::assertAmounts($expected, [
            'floor' => $finding->floor, 'ceiling' => $finding->ceiling, 'out' => $finding->out,
            'final' => $finding->final, 'owner_floor' => $finding->ownerFloor,
        ], 'audit');
    }

    /**
     * @param array<string, mixed> $input
     * @param array<string, mixed> $expected
     */
    #[DataProvider('premiumAuditCases')]
    public function testAuditOfAPremiumRelation(array $input, array $expected): void
    {
        $config = ParityRelations::config($input['relations_patch'] ?? []);
        $rooms = ParityRelations::rooms();
        $calendar = new ParityNightCalendar();
        $today = Days::date($input['today'] ?? $input['night']);
        $premium = self::relation('recanto-afrodite-premium-over-max', $config);
        $derive = $input['derive'] ? self::relation('recanto-afrodite-15-over-double', $config) : null;
        $audit = new PremiumAudit($config->settings, self::facts($input, $rooms), RecordedHooks::bundle($input, $calendar), $today);

        $finding = $audit->check($premium, $premium->params, $derive, Days::date($input['night']), (float) $input['others']);

        self::assertSame($expected['premium'], $premium->params->premiumFor($calendar->nightType(Days::date($input['night']))), 'premium');
        self::assertAmounts(
            ['floor' => $expected['floor'], 'ceiling' => $expected['ceiling'], 'out' => $expected['out']],
            ['floor' => $finding->floor, 'ceiling' => $finding->ceiling, 'out' => $finding->out],
            'premium audit'
        );
    }

    /**
     * @param array<string, mixed> $input
     * @param array<string, mixed> $expected
     */
    #[DataProvider('auditRunCases')]
    public function testWholeAuditAndItsPlanItems(array $input, array $expected): void
    {
        $auditor = self::auditor($input, $input['today']);
        $findings = $auditor->run(new DateRange(Days::date($input['from']), Days::date($input['to'])));

        self::assertAmounts($expected['findings'], array_map(static fn ($finding): array => [
            'room' => $finding->room, 'night' => Days::key($finding->night), 'floor' => $finding->floor,
            'ceiling' => $finding->ceiling, 'current' => $finding->current,
        ], $findings), 'findings');
        self::assertAmounts($expected['items'], array_map(static fn (DraftItem $item): array => $item->toArray(), $auditor->toPlanItems($findings)), 'plan items');
    }

    /**
     * @param array<string, mixed> $input
     * @param array<string, mixed> $expected
     */
    #[DataProvider('premiumBoundsCases')]
    public function testBoundsOfTheTopRoom(array $input, array $expected): void
    {
        $config = ParityRelations::config($input['relations_patch'] ?? []);
        $rooms = ParityRelations::rooms();
        $graph = new RelationGraph($config->relations, $rooms);
        $relation = self::relation('recanto-afrodite-premium-over-max', $config);
        $night = Days::date($input['night']);
        $hooks = RecordedHooks::bundle(['hooks' => ['Afrodite|' . $input['night'] => ['band' => $input['band']]], 'band_last_night' => $input['band_last_night']], new ParityNightCalendar());

        $bounds = PremiumBounds::forNight($relation, $graph->members($relation), $night, self::facts($input, $rooms), $hooks->calendar, $hooks->bands, Days::date($input['today']));

        self::assertAmounts($expected, ['floor' => $bounds->floor, 'ceiling' => $bounds->ceiling], 'bounds');
    }

    /**
     * @param array<string, mixed> $input
     */
    #[DataProvider('listingCapCases')]
    public function testListingCapChain(array $input, float $expected): void
    {
        $chain = new ListingCapChain(ParityRelations::graph($input), ParityRelations::config()->settings);

        self::assertEqualsWithDelta($expected, $chain->capFor($input['room']), self::AMOUNT_TOLERANCE);
    }

    /**
     * @param array<string, mixed>       $input
     * @param list<array<string, mixed>> $expected
     */
    #[DataProvider('listingCapPassCases')]
    public function testListingCapPass(array $input, array $expected): void
    {
        $draft = new PlanDraft(array_map(self::draftItem(...), $input['items']));
        (new ListingCapChain(ParityRelations::graph($input), ParityRelations::config()->settings))->apply($draft);

        self::assertAmounts($expected, array_map(static fn (DraftItem $item): array => $item->toArray(), $draft->items()), 'items');
    }

    /**
     * @param array<string, mixed> $input
     */
    #[DataProvider('triggerCases')]
    public function testOccupancyTrigger(array $input, bool $expected): void
    {
        $switch = self::relation('villa-balcony-switch-80');
        self::assertInstanceOf(SwitchParams::class, $switch->params);

        self::assertSame($expected, $switch->params->isHit($input['sold'], $input['units']));
    }

    /**
     * @param array<string, mixed>       $input
     * @param list<array<string, mixed>> $expected
     * @param array<string, mixed>       $case
     */
    #[DataProvider('bandCheckCases')]
    public function testDiscountBandRuleOfTheValidator(array $input, array $expected, array $case): void
    {
        $config = ParityRelations::config($input['relations_patch'] ?? []);
        $rooms = ParityRelations::rooms();
        $graph = new RelationGraph($config->relations, $rooms);
        $hooks = RecordedHooks::bundle($input, new ParityNightCalendar());
        $fresh = [];
        foreach ($input['fresh'] as $key => $row) {
            $fresh[$key] = new FreshNight($row['price'] === null ? null : (float) $row['price'], $row['free'], $row['sold'], $row['total']);
        }
        $values = new PlanNightValues(
            array_map('floatval', $input['planned']),
            array_map('floatval', $input['ceilings']),
            $input['percent_floors'],
            $input['order_refs'],
            $fresh,
        );
        $report = null;
        if ($input['satisfied_by_switch'] !== []) {
            $report = new RelationReport();
            foreach ($input['satisfied_by_switch'] as $key) {
                [$room, $night] = RoomNight::split($key);
                $report->record(new RelationDecision('villa-balcony-switch-80', RelationType::OccupancySwitch, $room, Days::date($night), Outcome::SwitchSatisfied));
            }
        }

        $issues = (new DiscountBandCheck($graph, $config->settings, $hooks->calendar, $hooks->orders))->check($values, $report);

        $got = array_map(static fn (BandIssue $issue): array => ['night' => Days::key($issue->night), 'code' => $issue->code, 'error' => $issue->isError], $issues);
        usort($got, static fn (array $a, array $b): int => [$a['night'], $a['code']] <=> [$b['night'], $b['code']]);
        self::assertSame($case['php_expected'] ?? $expected, $got);
    }

    public function testTheConfiguredBandsGiveTheEngineBands(): void
    {
        $config = ParityRelations::config();
        $checked = 0;
        foreach (ParityRelations::cases('pace.coerencia') as $case) {
            foreach ($case['input']['hooks'] as $key => $hooks) {
                if (!array_key_exists('band', $hooks)) {
                    continue;
                }
                [$room, $night] = RoomNight::split($key);
                $strategy = RecordedHooks::bundle($case['input'], new ParityNightCalendar())->strategy;
                $bands = new ConfiguredPriceBands($config->priceBands, ParityRelations::rooms(), new PriceFactor(Fixtures::parityRules()), new ParityNightCalendar(), $strategy);
                $band = $bands->sentBand($room, Days::date($night));
                self::assertSame($hooks['band'], $band === null ? null : [$band->low, $band->high], $case['name'] . ' ' . $key);
                ++$checked;
            }
        }
        self::assertGreaterThan(20, $checked);
    }

    /**
     * @return array<string, array{array<string, mixed>, mixed, array<string, mixed>}>
     */
    public static function coherenceCases(): array
    {
        return ParityRelations::provide('pace.coerencia');
    }

    /**
     * @return array<string, array{array<string, mixed>, mixed, array<string, mixed>}>
     */
    public static function betweenAuditCases(): array
    {
        return ParityRelations::provide('ordem_recanto.faixa');
    }

    /**
     * @return array<string, array{array<string, mixed>, mixed, array<string, mixed>}>
     */
    public static function premiumAuditCases(): array
    {
        return ParityRelations::provide('ordem_recanto.faixa_afrodite');
    }

    /**
     * @return array<string, array{array<string, mixed>, mixed, array<string, mixed>}>
     */
    public static function auditRunCases(): array
    {
        return ParityRelations::provide('ordem_recanto.comparar');
    }

    /**
     * @return array<string, array{array<string, mixed>, mixed, array<string, mixed>}>
     */
    public static function premiumBoundsCases(): array
    {
        return ParityRelations::provide('pace.regra_afrodite');
    }

    /**
     * @return array<string, array{array<string, mixed>, mixed, array<string, mixed>}>
     */
    public static function listingCapCases(): array
    {
        return ParityRelations::provide('pace.teto_anuncio');
    }

    /**
     * @return array<string, array{array<string, mixed>, mixed, array<string, mixed>}>
     */
    public static function listingCapPassCases(): array
    {
        return ParityRelations::provide('pace.limitar_ao_maximo');
    }

    /**
     * @return array<string, array{array<string, mixed>, mixed, array<string, mixed>}>
     */
    public static function triggerCases(): array
    {
        return ParityRelations::provide('plano_dono.balcony_no_mercado');
    }

    /**
     * @return array<string, array{array<string, mixed>, mixed, array<string, mixed>}>
     */
    public static function bandCheckCases(): array
    {
        return ParityRelations::provide('validar_plano.validar_balcony');
    }

    /**
     * @param array<string, mixed>               $input
     * @param list<RoomType> $rooms
     */
    private static function facts(array $input, array $rooms): NightFacts
    {
        return new NightFacts(
            $rooms,
            ParityRelations::calendar($input['calendar']),
            array_map('floatval', $input['decided'] ?? []),
            array_map('floatval', $input['strategy_decided'] ?? []),
            $input['light_filter'] ?? null,
            $input['blocked'] ?? [],
        );
    }

    /**
     * @param array<string, mixed> $input
     */
    private static function auditor(array $input, string $today): RelationAuditor
    {
        $config = ParityRelations::config($input['relations_patch'] ?? []);
        $rooms = ParityRelations::rooms($input['rooms_patch'] ?? []);
        $graph = new RelationGraph($config->relations, $rooms);
        $hooks = RecordedHooks::bundle($input, new ParityNightCalendar());

        return new RelationAuditor($graph, $config->settings, self::facts($input, $rooms), $hooks, Days::date($today));
    }

    private static function relation(string $id, ?RelationsConfig $config = null): Relation
    {
        foreach (($config ?? ParityRelations::config())->relations as $relation) {
            if ($relation->id === $id) {
                return $relation;
            }
        }
        self::fail('The parity relations have no relation ' . $id);
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function draftItem(array $row): DraftItem
    {
        return new DraftItem(
            $row['room'],
            Days::date($row['night']),
            (float) $row['price_before'],
            $row['floor'] === null ? null : (float) $row['floor'],
            $row['ceiling'] === null ? null : (float) $row['ceiling'],
            $row['order_ref'],
            $row['engine_step'],
            $row['strategy_item'],
            $row['protection'],
        );
    }

    private static function pluginRelative(string $path): string
    {
        return (string) preg_replace('#^ritmo-revenue/#', '', $path);
    }

    private static function assertAmounts(mixed $expected, mixed $actual, string $where): void
    {
        if ((is_int($expected) || is_float($expected)) && (is_int($actual) || is_float($actual))) {
            self::assertEqualsWithDelta((float) $expected, (float) $actual, self::AMOUNT_TOLERANCE, $where);

            return;
        }
        if (is_array($expected) && is_array($actual)) {
            self::assertSame(array_keys($expected), array_keys($actual), $where . ': keys');
            foreach ($expected as $key => $value) {
                self::assertAmounts($value, $actual[$key], $where . '.' . $key);
            }

            return;
        }
        self::assertSame($expected, $actual, $where);
    }
}
