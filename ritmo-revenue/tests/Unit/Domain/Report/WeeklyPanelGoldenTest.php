<?php

declare(strict_types=1);

namespace Ritmo\Tests\Unit\Domain\Report;

use PHPUnit\Framework\TestCase;
use Ritmo\Domain\Report\Weekly\Moment;
use Ritmo\Domain\Report\Weekly\ShareRow;
use Ritmo\Domain\Report\Weekly\WeeklyPanel;
use Ritmo\Domain\Report\Weekly\WeeklyReport;
use Ritmo\Domain\Support\Days;
use Ritmo\Tests\Support\Golden;

/**
 * painel_semanal.montar against WeeklyPanel (the reference's fixed start 2026-09-07 is the parity
 * reports.weekly.start_date).
 */
final class WeeklyPanelGoldenTest extends TestCase
{
    private const MOMENTS = ['passada' => Moment::Past, 'atual' => Moment::Current, 'futura' => Moment::Future];

    public function testThePanelMatchesMontar(): void
    {
        $cases = ReportGolden::cases('painel_semanal.montar');
        self::assertCount(2, $cases);
        $lines = ReportGolden::lines(ReportGolden::rawBookings());
        foreach ($cases as $n => $case) {
            $panel = WeeklyPanel::of($lines, ReportGolden::instant($case['input']['ate']), ReportGolden::capacities(), ReportGolden::allTargets(), ReportGolden::TIMEZONE, Days::date('2026-09-07'));
            $e = $case['expected'];
            self::assertMade($e['semanas'], $panel, "case $n");
            self::assertStays($e['estadias'], $panel, "case $n");
            self::assertGoals($e['metas'], $panel, "case $n");
            self::assertShares($e['canais'], $panel->channels, 'canal', static fn (string $k): string => ReportGolden::CHANNELS[$k], "case $n channels");
            self::assertShares($e['quartos'], $panel->rooms, 'quarto', static fn (string $k): string => $k, "case $n rooms");
        }
    }

    public function testWithoutAStartTheMadeTableCoversTheLast26Weeks(): void
    {
        $panel = WeeklyPanel::of([], ReportGolden::instant('2026-11-13T13:00:00Z'), ['p' => 4], [], ReportGolden::TIMEZONE, null);
        self::assertCount(WeeklyPanel::DEFAULT_WEEKS_BACK + 1, $panel->made);
        self::assertSame('2026-05-11', Days::key($panel->made[0]->monday));
        self::assertCount(17, $panel->stays);
        self::assertSame(0.0, $panel->stays[0]->occupancy);
    }

    /**
     * @param list<array<string, mixed>> $expected
     */
    private static function assertMade(array $expected, WeeklyReport $panel, string $where): void
    {
        self::assertCount(count($expected), $panel->made, "$where made");
        foreach ($expected as $i => $e) {
            $row = $panel->made[$i];
            $at = "$where made {$e['semana']} {$e['pousada']}";
            self::assertSame($e['semana'], Days::key($row->monday), $at);
            self::assertSame(Golden::propertyId($e['pousada']), $row->propertyId, $at);
            self::assertSame($e['completa'], $row->complete, "$at complete");
            BookingLineGoldenTest::assertTotals(self::totals($e), $row->totals, $at);
            self::assertSame($e['cancelamentos_na_semana'], $row->cancellations, "$at cancellations");
            self::assertEqualsWithDelta($e['receita_cancelada_na_semana'], $row->cancelledRevenue, Golden::AMOUNT_TOLERANCE, "$at cancelled revenue");
        }
    }

    /**
     * @param list<array<string, mixed>> $expected
     */
    private static function assertStays(array $expected, WeeklyReport $panel, string $where): void
    {
        self::assertCount(count($expected), $panel->stays, "$where stays");
        foreach ($expected as $i => $e) {
            $row = $panel->stays[$i];
            $at = "$where stays {$e['semana']} {$e['pousada']}";
            self::assertSame($e['semana'], Days::key($row->monday), $at);
            self::assertSame(self::MOMENTS[$e['momento']], $row->moment, "$at moment");
            self::assertSame($e['diarias'], $row->roomNights, "$at nights");
            self::assertSame($e['capacidade'], $row->capacity, "$at capacity");
            self::assertSame((float) $e['ocupacao'], $row->occupancy, "$at occupancy");
            self::assertEqualsWithDelta($e['receita'], $row->revenue, Golden::AMOUNT_TOLERANCE, "$at revenue");
            BookingLineGoldenTest::assertAmount($e['diaria_media'], $row->adr, "$at adr");
            BookingLineGoldenTest::assertAmount($e['revpar'], $row->revpar, "$at revpar");
        }
    }

    /**
     * @param list<array<string, mixed>> $expected
     */
    private static function assertGoals(array $expected, WeeklyReport $panel, string $where): void
    {
        self::assertSame(count($expected), array_sum(array_map('count', $panel->goals)), "$where goals");
        foreach ($expected as $e) {
            $s = $panel->goals[$e['mes']][Golden::propertyId($e['pousada'])];
            $at = "$where goals {$e['mes']} {$e['pousada']}";
            self::assertEqualsWithDelta($e['realizado'], $s->realisedRevenue, Golden::AMOUNT_TOLERANCE, "$at realised");
            self::assertEqualsWithDelta($e['vendido_a_frente'], $s->aheadRevenue, Golden::AMOUNT_TOLERANCE, "$at ahead");
            self::assertEqualsWithDelta($e['total'], $s->revenue, Golden::AMOUNT_TOLERANCE, "$at total");
            BookingLineGoldenTest::assertAmount($e['minimo_dono'], $s->minimum, "$at minimum");
            BookingLineGoldenTest::assertAmount($e['falta'], $s->gapToMinimum, "$at gap");
            self::assertSame((float) $e['ocupacao_mes'], $s->occupancy, "$at occupancy");
            BookingLineGoldenTest::assertAmount($e['diaria_media'], $s->adr, "$at adr");
            self::assertSame($e['diarias_livres'], $s->freeNights, "$at free");
            BookingLineGoldenTest::assertAmount($e['diaria_necessaria'], $s->adrNeeded, "$at adr needed");
            self::assertSame($e['atingido'] === null ? null : (float) $e['atingido'], $s->achieved(4), "$at achieved");
        }
    }

    /**
     * @param list<array<string, mixed>> $expected
     * @param list<ShareRow>             $actual
     * @param \Closure(string): string   $key
     */
    private static function assertShares(array $expected, array $actual, string $field, \Closure $key, string $where): void
    {
        self::assertCount(count($expected), $actual, $where);
        $byKey = [];
        foreach ($actual as $row) {
            $byKey[$row->propertyId . '|' . $row->key] = $row;
        }
        foreach ($expected as $e) {
            $row = $byKey[Golden::propertyId($e['pousada']) . '|' . $key($e[$field])] ?? null;
            self::assertNotNull($row, "$where {$e[$field]}");
            BookingLineGoldenTest::assertTotals(self::totals($e), $row->totals, "$where {$e[$field]}");
            self::assertSame((float) $e['participacao'], $row->share, "$where {$e[$field]} share");
        }
    }

    /**
     * The panel's row names => agrega's (the panel leaves out the cancelled revenue of the rows).
     *
     * @param array<string, mixed> $e
     *
     * @return array<string, mixed>
     */
    private static function totals(array $e): array
    {
        return [
            'reservas' => $e['reservas'], 'canceladas' => $e['canceladas'], 'ativas' => $e['ativas'], 'diarias' => $e['diarias'],
            'receita' => $e['receita'], 'adr' => $e['diaria_media'], 'antecedencia' => $e['antecedencia_dias'], 'estadia' => $e['estadia_noites'],
        ];
    }
}
