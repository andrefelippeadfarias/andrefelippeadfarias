<?php

declare(strict_types=1);

namespace Ritmo\Tests\Unit\Domain\Report;

use Ritmo\Tests\Support\ParityNames;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Ritmo\Domain\Report\Targets\ForecastConfig;
use Ritmo\Domain\Report\Targets\HolidayForecast;
use Ritmo\Domain\Report\Targets\HolidayTarget;
use Ritmo\Domain\Report\Targets\RevenueForecast;
use Ritmo\Domain\Report\Targets\RevenueTargets;
use Ritmo\Domain\Report\Targets\Scenario;
use Ritmo\Domain\Report\Targets\TargetInputs;

/**
 * metas_faturamento.feriados, meses and por_pousada against HolidayForecast, RevenueForecast and
 * RevenueTargets, for the module's own inputs and for an invented set.
 *
 * Divergence RP-X4: the reference replaces 2026-10 by the book plus a last-minute estimate (not
 * compared) and shifts 2027-03 and 2027-04 by Easter; here those two shifts are typed as the
 * months' adjustments (2027-03: Easter's forecast less three ordinary March nights; 2027-04: the
 * April amount), which must give the reference's numbers.
 */
final class RevenueTargetsGoldenTest extends TestCase
{
    private const SCENARIOS = ['piso' => Scenario::Floor, 'base' => Scenario::Base, 'meta' => Scenario::Goal];

    public function testHolidaysMatchFeriados(): void
    {
        foreach (ReportGolden::cases('metas_faturamento.feriados') as $case) {
            foreach (self::holidays($case['input']['insumos']) as $name => $holiday) {
                $forecast = HolidayForecast::of($holiday);
                foreach (self::SCENARIOS as $key => $scenario) {
                    self::assertSame($case['expected'][$name][$key], $forecast[$scenario->value], "{$case['input']['label']} $name $key");
                }
                self::assertSame([], $holiday->belowFloor((float) $case['input']['insumos']['feriados'][$name]['piso_skill']));
            }
        }
    }

    public function testMonthsMatchMeses(): void
    {
        foreach (ReportGolden::cases('metas_faturamento.meses') as $case) {
            $in = $case['input']['insumos'];
            $label = $case['input']['label'];
            $inputs = self::inputs($in, self::easterAdjustments($in));
            $months = array_values(array_filter($case['input']['meses'], static fn (string $m): bool => $m !== '2026-10'));
            $onBooks = array_map('floatval', $in['livro']);
            $forecast = RevenueForecast::months($months, $in['unidades'], $inputs, $onBooks, self::config($in, $case['input']['dono']));
            self::assertCount(count($months), $forecast);
            foreach ($forecast as $row) {
                self::assertSame([], $row->missing);
                self::assertSame((float) $case['expected'][$row->month]['livro'], $row->onBooks);
                foreach (self::SCENARIOS as $key => $scenario) {
                    self::assertSame($case['expected'][$row->month][$key], $row->of($scenario), "$label {$row->month} $key");
                }
            }
        }
    }

    public function testPerPropertyTargetsMatchPorPousada(): void
    {
        foreach (ReportGolden::cases('metas_faturamento.por_pousada') as $case) {
            $in = $case['input']['insumos'];
            $targets = RevenueTargets::of($case['input']['meses'], $in['unidades'], self::inputs($in, []), self::config($in, $case['input']['dono']));
            self::assertCount(count($case['input']['meses']) * count($in['unidades']), $targets);
            foreach ($targets as $t) {
                $e = $case['expected'][$t->month][$t->propertyId];
                $at = "{$case['input']['label']} {$t->month} {$t->propertyId}";
                self::assertSame($e['hoje'], $t->today, "$at today");
                self::assertSame($e['realista'], $t->realistic, "$at realistic");
                self::assertSame($e['maximo'], $t->maximum, "$at maximum");
                self::assertSame((float) $e['minimo_dono'], $t->minimum, "$at minimum");
                self::assertSame($e['revpar_minimo_dono'], $t->revparOfMinimum, "$at revpar");
                self::assertSame($e['temporada'] === 'alta', $t->highSeason, "$at season");
                self::assertSame((float) $e['minimo_dono_vs_realista'], $t->minimumVsRealistic, "$at vs realistic");
            }
        }
    }

    public function testAMonthWithoutMarketInputsIsListedAndNeverBelowTheBooks(): void
    {
        $forecast = RevenueForecast::months(['2027-05'], ['a' => 3, 'b' => 2], ['2027-05' => ['a' => new TargetInputs(50, 400)]], ['2027-05' => 90000.0], new ForecastConfig());
        self::assertSame(['b'], $forecast[0]->missing);
        self::assertSame(90000, $forecast[0]->of(Scenario::Base));
        $alone = RevenueForecast::months(['2027-05'], ['a' => 3], ['2027-05' => ['a' => new TargetInputs(50, 400, 1.0, ['goal' => 1000.0])]], [], new ForecastConfig());
        self::assertSame(18600, $alone[0]->of(Scenario::Floor));
        self::assertSame(19600, $alone[0]->of(Scenario::Goal));
    }

    public function testAHolidayRateBelowTheFloorIsFound(): void
    {
        $holiday = new HolidayTarget('p1', 30, 5, 4000.0, ['floor' => 0.5, 'base' => 0.6, 'goal' => 0.7], ['floor' => 700, 'base' => 800, 'goal' => 900]);
        self::assertSame([Scenario::Floor], $holiday->belowFloor(750.0));
        $this->expectException(InvalidArgumentException::class);
        new HolidayTarget('p1', 30, 5, 4000.0, ['floor' => 1.5, 'base' => 0.6, 'goal' => 0.7], ['floor' => 700, 'base' => 800, 'goal' => 900]);
    }

    public function testConfigurationRangesAreChecked(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new ForecastConfig(['base' => 3.0]);
    }

    /**
     * @param array<string, mixed> $in
     *
     * @return array<string, HolidayTarget>
     */
    private static function holidays(array $in): array
    {
        $out = [];
        foreach ($in['feriados'] as $name => $f) {
            $occupancy = $adr = [];
            foreach (array_values(self::SCENARIOS) as $i => $scenario) {
                $occupancy[$scenario->value] = (float) $f['ocupacao'][$i];
                $adr[$scenario->value] = (float) $f['diaria'][$i];
            }
            $out[$name] = new HolidayTarget($name, $f['diarias'], $f['vendidas'], (float) $f['receita'], $occupancy, $adr);
        }

        return $out;
    }

    /**
     * The Easter shifts the reference hard-codes, as typed adjustments (RP-X4).
     *
     * @param array<string, mixed> $in
     *
     * @return array<string, array<string, float>> month => scenario value => amount
     */
    private static function easterAdjustments(array $in): array
    {
        $easter = null;
        foreach (self::holidays($in) as $name => $holiday) {
            if (str_starts_with($name, 'Páscoa')) {
                $easter = HolidayForecast::of($holiday);
            }
        }
        $out = [];
        foreach (self::SCENARIOS as $key => $scenario) {
            $out['2027-03'][$scenario->value] = (float) ($easter[$scenario->value] - 3 * $in['pascoa_noites_normais_marco'][$key]);
            $out['2027-04'][$scenario->value] = (float) $in['abril_sem_pascoa'][$key];
        }

        return $out;
    }

    /**
     * @param array<string, mixed>                $in
     * @param array<string, array<string, float>> $adjust month => scenario value => amount (first property)
     *
     * @return array<string, array<string, TargetInputs>>
     */
    private static function inputs(array $in, array $adjust): array
    {
        $out = [];
        $first = array_key_first($in['unidades']);
        foreach ($in['mercado_ano_passado'] as $month => $byProperty) {
            foreach ($byProperty as $property => [$occupancy, $adr]) {
                $cap = $property === $first ? (float) ($in['teto_95_' . ParityNames::property('first')][$month] ?? 1.0) : 1.0;
                $out[$month][$property] = new TargetInputs((float) $occupancy, (float) $adr, $cap, $property === $first ? ($adjust[$month] ?? []) : []);
            }
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $in
     * @param array<string, mixed> $owner
     */
    private static function config(array $in, array $owner): ForecastConfig
    {
        $growth = $index = [];
        foreach (self::SCENARIOS as $key => $scenario) {
            $growth[$scenario->value] = (float) $in['cenarios'][$key]['crescimento'];
            $index[$scenario->value] = array_map('floatval', $in['cenarios'][$key]['indice']);
        }
        $high = array_values(array_unique(array_map(static fn (string $m): int => (int) substr($m, 5), $owner['meses_alta'])));

        return new ForecastConfig($growth, $index, 1.05, array_map('floatval', $owner['indice_maximo']), $high, [
            'high' => array_map('floatval', $owner['alta']),
            'low' => array_map('floatval', $owner['baixa']),
        ]);
    }
}
