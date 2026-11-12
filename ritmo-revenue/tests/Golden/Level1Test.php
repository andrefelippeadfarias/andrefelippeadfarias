<?php

declare(strict_types=1);

namespace Ritmo\Tests\Golden;

use PHPUnit\Framework\TestCase;
use Ritmo\Domain\Calendar\HolidayCalendar;
use Ritmo\Domain\Market\AnchorBuilder;
use Ritmo\Domain\Market\AnchorLookup;
use Ritmo\Domain\Market\PriceFactor;
use Ritmo\Domain\Market\Quantile;
use Ritmo\Domain\Model\AnchorOrigin;
use Ritmo\Domain\Model\BookingRecord;
use Ritmo\Domain\Model\BookingStatus;
use Ritmo\Domain\Model\DateOverride;
use Ritmo\Domain\Model\InventoryNight;
use Ritmo\Domain\Model\MarketAnchor;
use Ritmo\Domain\Model\NightType;
use Ritmo\Domain\Model\PriceNight;
use Ritmo\Domain\Model\PricePlan;
use Ritmo\Domain\Plan\PercentRounding;
use Ritmo\Domain\Plan\PlanItem;
use Ritmo\Domain\Pricing\DecisionKind;
use Ritmo\Domain\Pricing\Floors;
use Ritmo\Domain\Pricing\LedgerStep;
use Ritmo\Domain\Pricing\NightContext;
use Ritmo\Domain\Pricing\PaceBasis;
use Ritmo\Domain\Pricing\PaceCurve;
use Ritmo\Domain\Pricing\PaceDecision;
use Ritmo\Domain\Pricing\PaceEngine;
use Ritmo\Domain\Pricing\PaceMeter;
use Ritmo\Domain\Pricing\RuleCode;
use Ritmo\Domain\Pricing\RuleInForce;
use Ritmo\Domain\Pricing\StepDirection;
use Ritmo\Domain\Pricing\StepSize;
use Ritmo\Domain\Pricing\TargetPercentile;
use Ritmo\Domain\Report\OccupancyWindows;
use Ritmo\Domain\Report\PmsPricingMismatch;
use Ritmo\Domain\Support\DateRange;
use Ritmo\Domain\Support\Days;
use Ritmo\Domain\Support\FixedClock;
use Ritmo\Domain\Support\Num;
use Ritmo\Tests\Support\Fixtures;
use Ritmo\Tests\Support\Golden;

/**
 * Parity of the engine with the reference Python engine: every level-1 golden file, computed in
 * PHP from the parity preset, must give the reference's result.
 *
 * Unrounded floats must match within 1e-9, amounts within 0.01, whole percentages, counts and
 * classifications exactly. A golden file without a test here fails the suite.
 */
final class Level1Test extends TestCase
{
    /** Largest accepted difference for floats the reference does not round. */
    private const FLOAT_TOLERANCE = 1e-9;

    /** Golden function => the test of this class that checks it. */
    private const COVERAGE = [
        'ancora_mercado.ancora_da_noite' => 'testAnchorLookupMatchesAncoraDaNoite',
        'ancora_mercado.construir' => 'testMonthlyFallbacksMatchConstruir',
        'ancora_mercado.percentil_de' => 'testPercentileOfMatchesPercentilDe',
        'ancora_mercado.quantil' => 'testQuantileMatchesQuantil',
        'ancora_mercado.substitui' => 'testReplacesMatchesSubstitui',
        'ancora_mercado.tipo_noite' => 'testHolidayCalendarMatchesTipoNoite',
        'ancora_mercado.valor_percentil' => 'testValueAtMatchesValorPercentil',
        'beds24.comparar' => 'testPmsPricingMismatchMatchesComparar',
        'builtins.round' => 'testRoundHalfEvenMatchesPythonRound',
        'ocupacao.calcular' => 'testOccupancyWindowsMatchCalcular',
        'pace.curva' => 'testPaceCurveMatchesCurva',
        'pace.efetivo' => 'testEffectiveAmountsMatchEfetivo',
        'pace.fator_publico' => 'testPublicFactorMatchesFatorPublico',
        'pace.limite_seguranca' => 'testSafetyLimitMatchesLimiteSeguranca',
        'pace.passo' => 'testStepMatchesPasso',
        'pace.percentil_alvo' => 'testTargetPercentileMatchesPercentilAlvo',
        'pace.pisos_enviados' => 'testHardFloorMatchesPisosEnviados',
        'pace.plano.core' => 'testPaceEngineAndPaceBasisMatchTheCoreOfPlano',
        'pace.pmax' => 'testMaxStepMatchesPmax',
        'pace.preco_no_vigente' => 'testRuleInForceMatchesPrecoNoVigente',
        'pace.status_da_noite' => 'testPaceMeterMatchesStatusDaNoite',
        'pace.teto_anuncio' => 'testHighestFloorMatchesTetoAnuncio',
        'validar_plano.em_reais' => 'testPercentToAmountMatchesEmReais',
        'validar_plano.montar_payload' => 'testPercentRoundingMatchesMontarPayload',
        'validar_plano.piso_absoluto' => 'testSafetyLimitMatchesPisoAbsoluto',
        'validar_plano.piso_pct' => 'testFloorToPercentMatchesPisoPct',
        'validar_plano.teto_pct' => 'testCeilingToPercentMatchesTetoPct',
    ];

    public function testEveryGoldenFileHasATest(): void
    {
        $onDisk = array_map(
            static fn (string $file): string => basename($file, '.json'),
            glob(Fixtures::path('golden/level1/*.json')) ?: []
        );
        sort($onDisk);
        $covered = array_keys(self::COVERAGE);
        sort($covered);

        self::assertSame($onDisk, $covered, 'every file of tests/fixtures/golden/level1 needs a test in Level1Test::COVERAGE');
        self::assertSame($onDisk, self::sorted(array_column(Golden::manifest()['files'], 'function')), 'MANIFEST.json must list every golden file');
        foreach (self::COVERAGE as $function => $method) {
            self::assertTrue(method_exists($this, $method), "$function: the test $method does not exist");
            self::assertNotSame([], Golden::cases($function), "$function has no cases");
        }
    }

    // ---------- Support (Num)

    public function testRoundHalfEvenMatchesPythonRound(): void
    {
        foreach (Golden::cases('builtins.round') as $case) {
            self::assertSame((float) $case['expected'], Num::roundHalfEven((float) $case['input']['value'], $case['input']['decimals']), json_encode($case['input']));
        }
    }

    public function testFloorToPercentMatchesPisoPct(): void
    {
        foreach (Golden::cases('validar_plano.piso_pct') as $case) {
            self::assertSame($case['expected'], Num::floorToPercent((float) $case['input']['reais'], (float) $case['input']['base']), json_encode($case['input']));
        }
    }

    public function testCeilingToPercentMatchesTetoPct(): void
    {
        foreach (Golden::cases('validar_plano.teto_pct') as $case) {
            self::assertSame($case['expected'], Num::ceilingToPercent((float) $case['input']['reais'], (float) $case['input']['base']), json_encode($case['input']));
        }
    }

    public function testPercentToAmountMatchesEmReais(): void
    {
        foreach (Golden::cases('validar_plano.em_reais') as $case) {
            self::assertSame((float) $case['expected'], Num::percentToAmount((float) $case['input']['pct'], (float) $case['input']['base']), json_encode($case['input']));
        }
    }

    public function testEffectiveAmountsMatchEfetivo(): void
    {
        foreach (Golden::cases('pace.efetivo') as $case) {
            $in = $case['input'];
            $percent = $in['para_baixo'] ? Num::ceilingToPercent((float) $in['reais'], (float) $in['base']) : Num::floorToPercent((float) $in['reais'], (float) $in['base']);

            self::assertEqualsWithDelta($case['expected'], Num::percentToAmount((float) $percent, (float) $in['base'], 2), Golden::AMOUNT_TOLERANCE, json_encode($in));
        }
    }

    // ---------- Calendar

    public function testHolidayCalendarMatchesTipoNoite(): void
    {
        $calendar = new HolidayCalendar(Fixtures::parityRules());
        foreach (Golden::cases('ancora_mercado.tipo_noite') as $case) {
            $night = Days::date($case['input']['d']);

            self::assertSame(Golden::nightType($case['expected']['tipo']), $calendar->nightType($night), $case['input']['d']);
            self::assertSame($case['expected']['feriado'], $calendar->holidayOn($night)?->name, $case['input']['d']);
            self::assertSame($case['expected']['feriado'] !== null, $calendar->isInPeriod($night), $case['input']['d']);
        }
    }

    // ---------- Market

    public function testQuantileMatchesQuantil(): void
    {
        foreach (Golden::cases('ancora_mercado.quantil') as $case) {
            $actual = Quantile::of($case['input']['valores'], (float) $case['input']['p']);
            if ($case['expected'] === Golden::NAN) {
                self::assertNan($actual, json_encode($case['input']));
            } else {
                self::assertEqualsWithDelta($case['expected'], $actual, self::FLOAT_TOLERANCE, json_encode($case['input']));
            }
        }
    }

    public function testValueAtMatchesValorPercentil(): void
    {
        foreach (Golden::cases('ancora_mercado.valor_percentil') as $case) {
            $anchor = self::anchor('2026-11-11', $case['input']['anc']);

            self::assertEqualsWithDelta($case['expected'], AnchorLookup::valueAt($anchor, (float) $case['input']['pct']), self::FLOAT_TOLERANCE, json_encode($case['input']));
        }
    }

    public function testPercentileOfMatchesPercentilDe(): void
    {
        foreach (Golden::cases('ancora_mercado.percentil_de') as $case) {
            $anchor = self::anchor('2026-11-11', $case['input']['anc']);

            self::assertSame((float) $case['expected'], AnchorLookup::percentileOf($anchor, (float) $case['input']['preco_publico']), json_encode($case['input']));
        }
    }

    public function testReplacesMatchesSubstitui(): void
    {
        $rules = Fixtures::parityRules();
        $builder = new AnchorBuilder($rules);
        $outcomes = [];
        foreach (Golden::cases('ancora_mercado.substitui') as $case) {
            $in = $case['input'];
            self::assertSame($rules->maxSampleAgeDays(), $in['idade_max']);
            $anchors = [];
            foreach (['novo', 'velho'] as $side) {
                $anchors[$side] = self::anchor('2026-11-20', [
                    'p25' => 100, 'p50' => 200, 'p75' => 300, 'p90' => 400, 'max' => 500,
                    'n' => $in[$side]['n'],
                    'busca_em' => $in[$side]['busca_em'],
                ], $in[$side]['p50_medido'] ? AnchorOrigin::Measured : AnchorOrigin::Fallback);
            }
            $outcomes[(int) $case['expected']] = true;

            self::assertSame($case['expected'], $builder->replaces($anchors['novo'], $anchors['velho'], Days::date($in['hoje'])), json_encode($in));
        }
        self::assertCount(2, $outcomes, 'the cases must cover both answers');
    }

    public function testMonthlyFallbacksMatchConstruir(): void
    {
        $builder = new AnchorBuilder(Fixtures::parityRules());
        foreach (Golden::cases('ancora_mercado.construir') as $number => $case) {
            $anchors = [];
            foreach ($case['input']['noites'] as $night => $row) {
                $anchors[$night] = self::anchor($night, $row);
            }
            $fallbacks = $builder->monthlyFallbacks($anchors);

            self::assertSame(self::sorted(array_keys($case['expected'])), self::sorted(array_map(
                static fn (string $type): string => array_search($type, Golden::manifest()['vocabulary']['night_type'], true),
                array_keys($fallbacks)
            )), "case $number");
            foreach ($case['expected'] as $tipo => $months) {
                $ofType = $fallbacks[Golden::nightType($tipo)->value];
                self::assertSame(self::sorted(array_keys($months)), self::sorted(array_keys($ofType)), "case $number, $tipo");
                foreach ($months as $month => $expected) {
                    $fallback = $ofType[$month];
                    $where = "case $number, $tipo, $month";
                    foreach (['p25', 'p50', 'p75', 'p90', 'max'] as $point) {
                        self::assertSame((float) $expected[$point], $fallback->$point, "$where, $point");
                    }
                    self::assertSame($expected['busca_em'], Days::key($fallback->capturedAt), $where);
                    self::assertSame(AnchorOrigin::Fallback, $fallback->origin, $where);
                    self::assertSame(0, $fallback->n, $where);
                }
            }
        }
    }

    public function testAnchorLookupMatchesAncoraDaNoite(): void
    {
        $rules = Fixtures::parityRules();
        $vocabulary = Golden::manifest()['vocabulary'];
        $lookups = [];
        foreach (['todas' => null, 'semana' => NightType::Weekday] as $name => $onlyType) {
            $anchors = [];
            foreach (Fixtures::json('frozen/ancora_mercado.json')['noites'] as $night => $row) {
                if ($onlyType === null || $rules->nightType(Days::date($night)) === $onlyType) {
                    $anchors[$night] = self::anchor($night, $row);
                }
            }
            $lookups[$name] = new AnchorLookup($rules, $anchors);
        }

        $seen = [];
        foreach (Golden::cases('ancora_mercado.ancora_da_noite') as $case) {
            $where = json_encode($case['input']);
            $anchor = $lookups[$case['input']['noites']]->forNight(Days::date($case['input']['d']));
            $expected = $case['expected'];
            $seen[$expected['origem'] ?? 'none'] = true;
            if ($expected === null) {
                self::assertNull($anchor, $where);
                continue;
            }

            self::assertNotNull($anchor, $where);
            self::assertSame($case['input']['d'], Days::key($anchor->date), $where);
            foreach (['p25', 'p50', 'p75', 'p90', 'max'] as $point) {
                self::assertSame((float) $expected[$point], $anchor->$point, "$where, $point");
            }
            self::assertSame($expected['n'], $anchor->n, $where);
            self::assertSame($expected['busca_em'], $anchor->capturedAt === null ? null : Days::key($anchor->capturedAt), $where);
            self::assertSame($vocabulary['anchor_origin'][$expected['origem']], $anchor->origin->value, $where);
        }
        self::assertSame(['mes', 'mes_proximo', 'none', 'propria'], self::sorted(array_keys($seen)));
    }

    public function testPublicFactorMatchesFatorPublico(): void
    {
        $preset = Fixtures::parityPreset();
        $factors = new PriceFactor($preset->rules);
        foreach (Golden::cases('pace.fator_publico') as $case) {
            foreach ($preset->roomTypesOf(Golden::propertyId($case['input']['pousada'])) as $room) {
                foreach (NightType::cases() as $type) {
                    self::assertSame((float) $case['expected'], $factors->factor($room, PricePlan::PublicRate, $type), $room->code);
                }
            }
        }
    }

    // ---------- Pricing

    public function testPaceCurveMatchesCurva(): void
    {
        $curve = new PaceCurve(Fixtures::parityRules());
        foreach (Golden::cases('pace.curva') as $case) {
            self::assertEqualsWithDelta(
                $case['expected'],
                $curve->expected(Golden::nightType($case['input']['tipo']), $case['input']['dias']),
                self::FLOAT_TOLERANCE,
                json_encode($case['input'])
            );
        }
    }

    public function testPaceMeterMatchesStatusDaNoite(): void
    {
        $meter = new PaceMeter(Fixtures::parityRules());
        $seen = [];
        foreach (Golden::cases('pace.status_da_noite') as $case) {
            $in = $case['input'];
            $reading = $meter->measure(Golden::nightType($in['tipo']), $in['dias'], $in['vendidas'], $in['unidades']);
            $seen[$reading->status->value] = true;

            self::assertSame(Golden::paceStatus($case['expected']['status']), $reading->status, json_encode($in));
            self::assertEqualsWithDelta($case['expected']['vendido'], $reading->soldPercent, self::FLOAT_TOLERANCE, json_encode($in));
            self::assertEqualsWithDelta($case['expected']['curva'], $reading->expectedPercent, self::FLOAT_TOLERANCE, json_encode($in));
        }
        self::assertCount(4, $seen, 'the cases must cover the four statuses');
    }

    public function testTargetPercentileMatchesPercentilAlvo(): void
    {
        $preset = Fixtures::parityPreset();
        $target = new TargetPercentile($preset->rules);
        foreach (Golden::cases('pace.percentil_alvo') as $case) {
            $in = $case['input'];
            $property = $preset->property(Golden::propertyId($in['pousada']));

            self::assertSame((float) $case['expected'], $target->forNight($property, Golden::paceStatus($in['status']), $in['dias']), json_encode($in));
        }
    }

    public function testMaxStepMatchesPmax(): void
    {
        $steps = new StepSize(Fixtures::parityRules());
        foreach (Golden::cases('pace.pmax') as $case) {
            self::assertSame((float) $case['expected'], $steps->maxPercent($case['input']['dias']), 'D' . $case['input']['dias']);
            self::assertSame($case['expected'] / 2, $steps->maxPercent($case['input']['dias'], true), 'D' . $case['input']['dias']);
        }
    }

    public function testStepMatchesPasso(): void
    {
        $steps = new StepSize(Fixtures::parityRules());
        foreach (Golden::cases('pace.passo') as $case) {
            $in = $case['input'];

            self::assertEqualsWithDelta(
                $case['expected'],
                $steps->next((float) $in['atual'], (float) $in['alvo'], $in['dias'], $in['fraco']),
                self::FLOAT_TOLERANCE,
                json_encode($in)
            );
        }
    }

    public function testHardFloorMatchesPisosEnviados(): void
    {
        $preset = Fixtures::parityPreset();
        $floors = new Floors($preset->rules);
        $labels = Golden::manifest()['vocabulary']['floor_label'];
        $seen = [];
        foreach (Golden::cases('pace.pisos_enviados') as $case) {
            $where = json_encode($case['input']);
            $floor = $floors->hardFloor($preset->roomType($case['input']['curto']), Days::date($case['input']['d']));
            $seen[$floor->label] = true;

            self::assertEqualsWithDelta($case['expected']['piso'], $floor->amount, Golden::AMOUNT_TOLERANCE, $where);
            self::assertSame(self::floorLabel($labels, $case['expected']['origem']), $floor->label, $where);
        }
        self::assertSame([Floors::HOLIDAY, Floors::LISTING_MIN, Floors::OWNER], self::sorted(array_keys($seen)));
    }

    public function testSafetyLimitMatchesLimiteSeguranca(): void
    {
        $this->assertSafetyLimit('pace.limite_seguranca');
    }

    public function testSafetyLimitMatchesPisoAbsoluto(): void
    {
        $this->assertSafetyLimit('validar_plano.piso_absoluto');
    }

    public function testHighestFloorMatchesTetoAnuncio(): void
    {
        $preset = Fixtures::parityPreset();
        $floors = new Floors($preset->rules);
        foreach (Golden::cases('pace.teto_anuncio') as $case) {
            self::assertEqualsWithDelta(
                $case['expected'],
                $floors->highestFloor($preset->roomType($case['input']['curto'])),
                Golden::AMOUNT_TOLERANCE,
                $case['input']['curto']
            );
        }
    }

    public function testPaceEngineAndPaceBasisMatchTheCoreOfPlano(): void
    {
        $preset = Fixtures::parityPreset();
        $vocabulary = Golden::manifest()['vocabulary'];
        $basis = new PaceBasis($preset->rules);
        $outcomes = [];
        $groups = 0;
        foreach (Golden::cases('pace.plano.core') as $number => $case) {
            $in = $case['input'];
            $expected = $case['expected'];
            $where = sprintf('case %d: %s %s', $number, $in['curto'], $in['data']);
            $room = $preset->roomType($in['curto']);
            $property = $preset->property($room->propertyId);
            $clock = new FixedClock($in['agora']);
            self::assertSame($in['hoje'], Days::key($clock->today($property->timezone)), $where);
            $group = $basis->groupFor($room, $preset->roomTypesOf($property->id), $in['pousada_noite']);
            self::assertSame([$in['base_vendidas'], $in['base_total']], $group ?? [$in['vendidas'], $in['unidades']], "$where: pace basis");
            $groups += $group === null ? 0 : 1;

            $context = new NightContext(
                $property,
                $room,
                Days::date($in['data']),
                (float) $in['price'],
                $in['vendidas'],
                $in['unidades'],
                $in['anc'] === null ? null : self::anchor($in['data'], $in['anc'], AnchorOrigin::from($vocabulary['anchor_origin'][$in['anc']['origem']])),
                $in['min_price'] === null ? null : (float) $in['min_price'],
                $in['max_price'] === null ? null : (float) $in['max_price'],
                self::ledgerStep($in['ultimo_passo'], $vocabulary['step_direction']),
                $group[0] ?? null,
                $group[1] ?? null,
            );
            $decision = (new PaceEngine($preset->rules, $clock))->decide($context);
            $outcomes[$decision->reasonCode] = true;

            if ($expected['fora'] !== null) {
                self::assertSame(DecisionKind::Skipped, $decision->kind, $where);
                self::assertSame($vocabulary['left_out'][$expected['fora']], $decision->reasonCode, $where);
                continue;
            }
            $this->assertLine($expected['linha'], $decision, $context, $vocabulary, $where);
            $this->assertItem($expected['item'], $expected['linha'], $decision, $vocabulary, $where);
        }

        self::assertGreaterThan(100, $groups, 'the cases must cover rooms read with their group');
        foreach ([RuleCode::SOLD_OUT_PENDING_M2, RuleCode::NO_MARKET_ANCHOR, RuleCode::WITHIN_TOLERANCE, RuleCode::PACE_STEP, RuleCode::CADENCE_PREVIOUS_STEP_NOT_PUSHED, RuleCode::CADENCE_STEP_WITHIN_24H] as $code) {
            self::assertArrayHasKey($code, $outcomes, "the cases must cover the outcome $code");
        }
    }

    /**
     * The diagnosis of the night against the engine's line, rounded as the reference rounds it.
     *
     * @param array<string, mixed>                $line
     * @param array<string, array<string, string>> $vocabulary
     */
    private function assertLine(array $line, PaceDecision $decision, NightContext $context, array $vocabulary, string $where): void
    {
        self::assertNotNull($decision->reading, $where);
        self::assertSame(Golden::paceStatus($line['status']), $decision->reading->status, "$where: status");
        self::assertSame($line['vendido_pct'], Num::roundInt($decision->reading->soldPercent), "$where: sold %");
        self::assertSame($line['curva_pct'], Num::roundInt($decision->reading->expectedPercent), "$where: curve %");
        self::assertSame((float) $line['percentil_alvo'], $decision->targetPercentile, "$where: target percentile");
        self::assertSame($line['alvo_publico'], Num::roundInt($decision->targetPublic), "$where: public target");
        self::assertSame($line['alvo_enviado'], Num::roundInt($decision->targetSent), "$where: sent target");
        self::assertSame($line['atual_publico'], Num::roundInt($context->currentPrice * $decision->publicFactor), "$where: current public price");
        self::assertSame((float) $line['percentil_atual'], $decision->currentPercentile, "$where: current percentile");
        self::assertSame((float) $line['novo'], $decision->proposedPrice, "$where: new price");
        self::assertSame($line['ancora_fraca'], $decision->weakAnchor, "$where: weak anchor");
        self::assertSame($line['ancora_fraca'], $decision->applied(RuleCode::WEAK_ANCHOR), "$where: weak anchor in the trace");

        self::assertSame($line['preso_no_piso'] !== null, $decision->heldAtFloor, "$where: held at the floor");
        if ($line['preso_no_piso'] !== null) {
            $held = $decision->traceParams(RuleCode::HELD_AT_FLOOR);
            self::assertNotNull($held, $where);
            self::assertSame(self::floorLabel($vocabulary['floor_label'], $line['preso_no_piso']['origem']), $decision->floor?->label, "$where: floor label");
            self::assertSame($line['preso_no_piso']['piso'], Num::roundInt($decision->floor->amount), "$where: floor");
            self::assertSame($line['preso_no_piso']['publico'], Num::roundInt((float) $held['public']), "$where: floor as a public price");
            self::assertSame($line['preso_no_piso']['percentil'], Num::roundInt((float) $held['percentile']), "$where: percentile of the floor");
        }

        if ($line['direcao'] === null) {
            self::assertSame(DecisionKind::Hold, $decision->kind, $where);
            self::assertSame(RuleCode::WITHIN_TOLERANCE, $decision->reasonCode, $where);
            self::assertNull($decision->direction, $where);
        } elseif ($line['cadencia'] !== null) {
            self::assertSame(DecisionKind::Hold, $decision->kind, $where);
            self::assertSame($vocabulary['cadence'][$line['cadencia']], $decision->reasonCode, $where);
            self::assertSame(StepDirection::from($vocabulary['step_direction'][$line['direcao']]), $decision->direction, $where);
        } else {
            self::assertSame(DecisionKind::Step, $decision->kind, $where);
        }
    }

    /**
     * What would be written against the engine's item.
     *
     * @param array<string, mixed>|null           $item
     * @param array<string, mixed>                $line
     * @param array<string, array<string, string>> $vocabulary
     */
    private function assertItem(?array $item, array $line, PaceDecision $decision, array $vocabulary, string $where): void
    {
        if ($item === null) {
            self::assertFalse($decision->isStep(), "$where: no item expected");
            self::assertNull($decision->minPrice, $where);
            self::assertNull($decision->maxPrice, $where);

            return;
        }

        self::assertTrue($decision->isStep(), "$where: an item was expected");
        self::assertSame((float) $item['min_price'], $decision->minPrice, "$where: floor");
        self::assertSame($item['max_price'] === null ? null : (float) $item['max_price'], $decision->maxPrice, "$where: ceiling");
        self::assertSame($item['direcao'] === null ? null : $vocabulary['step_direction'][$item['direcao']], $decision->direction?->value, "$where: direction");
        self::assertSame($line['direcao'] === 'sobe', $decision->applied(RuleCode::CEILING_AT_TARGET), "$where: ceiling of a rise");
    }

    // ---------- Plan

    public function testRuleInForceMatchesPrecoNoVigente(): void
    {
        $preset = Fixtures::parityPreset();
        $moved = 0;
        foreach (Golden::cases('pace.preco_no_vigente') as $case) {
            $in = $case['input'];
            $where = json_encode($in);
            $rule = $in['regra'];
            $amount = static fn (string $field, string $type): ?float => ($rule[$field] ?? null) !== null && ($rule[$field . '_type'] ?? '') === $type ? (float) $rule[$field] : null;
            $override = new DateOverride(
                Days::date('2026-11-20'),
                minPercent: $amount('min_price', 'percent_base'),
                maxPercent: $amount('max_price', 'percent_base'),
                minFixed: $amount('min_price', 'fixed'),
                maxFixed: $amount('max_price', 'fixed'),
            );
            $base = $preset->roomType($in['curto'])->base;
            $price = RuleInForce::price((float) $in['preco'], RuleInForce::floor($override, $base), RuleInForce::ceiling($override, $base));

            self::assertEqualsWithDelta((float) $case['expected']['price'], $price, 0.01, $where);
            self::assertSame($case['expected']['preco_recalculo'] !== null, $price !== (float) $in['preco'], $where);
            $moved += $price !== (float) $in['preco'] ? 1 : 0;
        }
        self::assertGreaterThan(100, $moved, 'prices under the floor and over the ceiling are brought within them');
    }

    public function testPercentRoundingMatchesMontarPayload(): void
    {
        $preset = Fixtures::parityPreset();
        $rounding = new PercentRounding();
        foreach (Golden::cases('validar_plano.montar_payload') as $case) {
            $in = $case['input'];
            $where = json_encode($in);
            $item = new PlanItem(
                $in['curto'],
                Days::date('2026-11-11'),
                $in['min_price'] === null ? null : (float) $in['min_price'],
                $in['max_price'] === null ? null : (float) $in['max_price'],
            );
            $percent = $rounding->convert($item, $preset->roomType($in['curto']));

            self::assertSame($case['expected']['min_price'], $percent->minPercent === null ? null : (string) $percent->minPercent, $where);
            self::assertSame($case['expected']['max_price'], $percent->maxPercent === null ? null : (string) $percent->maxPercent, $where);
        }
    }

    // ---------- Report

    public function testOccupancyWindowsMatchCalcular(): void
    {
        $preset = Fixtures::parityPreset();
        $windows = new OccupancyWindows($preset->rules);
        foreach (Golden::cases('ocupacao.calcular') as $number => $case) {
            $today = Days::date($case['input']['hoje']);
            foreach (Golden::manifest()['vocabulary']['property'] as $engineName => $propertyId) {
                $rooms = $preset->roomTypesOf($propertyId);
                $inventory = [];
                foreach ($rooms as $room) {
                    foreach ($case['input']['calendario'][$room->code] as $night => $occupancy) {
                        [$sold, $units] = array_map('intval', explode('/', $occupancy));
                        self::assertSame($room->units, $units);
                        $inventory[$room->code][] = new InventoryNight(Days::date($night), $units - $sold);
                    }
                }
                $report = $windows->measure($preset->property($propertyId), $rooms, $inventory, $today);

                $this->assertWindows($case['expected'][$engineName], $report->property, "case $number, $engineName");
                foreach ($rooms as $room) {
                    $this->assertWindows($case['expected'][$room->code], $report->rooms[$room->code], "case $number, {$room->code}");
                }
            }
        }
    }

    /**
     * @param array<string, array{vendidas: int, total: int, pct: float|null}> $expected window days => numbers
     * @param array<int, \Ritmo\Domain\Report\WindowOccupancy>                 $actual
     */
    private function assertWindows(array $expected, array $actual, string $where): void
    {
        self::assertSame(array_map('intval', array_keys($expected)), array_keys($actual), $where);
        $goals = Fixtures::parityRules()->occupancyGoals();
        foreach ($expected as $days => $numbers) {
            $window = $actual[(int) $days];
            self::assertSame($numbers['vendidas'], $window->sold, "$where, $days days: sold");
            self::assertSame($numbers['total'], $window->capacity, "$where, $days days: capacity");
            self::assertSame($numbers['pct'] === null ? null : (float) $numbers['pct'], $window->percent, "$where, $days days: percent");
            self::assertSame($goals[(int) $days], $window->goal, "$where, $days days: goal");
            self::assertSame($numbers['pct'] === null ? null : $numbers['pct'] >= $goals[(int) $days], $window->meetsGoal(), "$where, $days days: goal met");
        }
    }

    public function testPmsPricingMismatchMatchesComparar(): void
    {
        $preset = Fixtures::parityPreset();
        $statuses = Golden::manifest()['vocabulary']['booking_status'];
        foreach (Golden::cases('beds24.comparar') as $number => $case) {
            $in = $case['input'];
            $bookings = [];
            foreach ($in['reservas'] as $booking) {
                $bookings[] = new BookingRecord(
                    $booking['id'],
                    $booking['quarto'],
                    Days::date($booking['arrival']),
                    Days::date($booking['departure']),
                    BookingStatus::from($statuses[$booking['status']]),
                    'direct',
                    'golden',
                    null,
                    null,
                    null,
                    null,
                    $preset->roomType($booking['quarto'])->pmsRef,
                    $booking['roomQty'],
                );
            }
            $pricing = [];
            foreach ($in['pricelabs'] as $code => $nights) {
                $units = $preset->roomType($code)->units;
                foreach ($nights as $night => $sold) {
                    $pricing[$code][] = new PriceNight(Days::date($night), 1000.0, null, null, null, $sold, $units);
                }
            }
            $range = new DateRange(Days::date($in['de']), Days::add(Days::date($in['ate']), -1));

            $actual = array_map(
                static fn ($mismatch): array => [
                    'quarto' => $mismatch->roomCode,
                    'data' => Days::key($mismatch->night),
                    'pricelabs' => $mismatch->pricingSold,
                    'beds24' => $mismatch->pmsSold,
                    'diferenca' => $mismatch->difference(),
                ],
                (new PmsPricingMismatch())->find($bookings, $pricing, $range)
            );
            self::assertNotSame([], $case['expected'], "case $number");
            self::assertSame($case['expected'], $actual, "case $number");
        }
    }

    // ---------- helpers

    private function assertSafetyLimit(string $function): void
    {
        $preset = Fixtures::parityPreset();
        $floors = new Floors($preset->rules);
        foreach (Golden::cases($function) as $case) {
            self::assertEqualsWithDelta(
                $case['expected'],
                $floors->safetyLimit($preset->roomType($case['input']['curto'])),
                Golden::AMOUNT_TOLERANCE,
                $function . ' ' . $case['input']['curto']
            );
        }
    }

    /**
     * A market anchor from the engine's dictionary (p25 ... max, optional n and busca_em).
     *
     * @param array<string, mixed> $row
     */
    private static function anchor(string $night, array $row, AnchorOrigin $origin = AnchorOrigin::Measured): MarketAnchor
    {
        return new MarketAnchor(
            Days::date($night),
            (float) $row['p25'],
            (float) $row['p50'],
            (float) $row['p75'],
            (float) $row['p90'],
            (float) $row['max'],
            (int) ($row['n'] ?? 0),
            isset($row['busca_em']) ? Days::date($row['busca_em']) : null,
            $origin,
        );
    }

    /**
     * @param array{direcao: string, gravado_em: string, chegou_ao_canal: bool}|null $step
     * @param array<string, string>                                                  $directions
     */
    private static function ledgerStep(?array $step, array $directions): ?LedgerStep
    {
        if ($step === null) {
            return null;
        }

        return new LedgerStep(StepDirection::from($directions[$step['direcao']]), new \DateTimeImmutable($step['gravado_em']), $step['chegou_ao_canal']);
    }

    /**
     * The plugin's label of the floor the engine names with a sentence.
     *
     * @param array<string, string> $labels sentence prefix => label
     */
    private static function floorLabel(array $labels, string $sentence): string
    {
        foreach ($labels as $prefix => $label) {
            if (str_starts_with($sentence, $prefix)) {
                return $label;
            }
        }
        self::fail("No floor label for \"$sentence\".");
    }

    /**
     * @param list<string> $values
     *
     * @return list<string>
     */
    private static function sorted(array $values): array
    {
        sort($values);

        return $values;
    }
}
