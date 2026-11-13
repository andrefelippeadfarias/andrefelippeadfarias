<?php

declare(strict_types=1);

namespace Ritmo\Tests\Unit\Domain\Report;

use PHPUnit\Framework\TestCase;
use Ritmo\Domain\Report\Booking\BookingTotals;
use Ritmo\Domain\Report\Month\MonthPerformance;
use Ritmo\Domain\Report\Month\MonthReport;
use Ritmo\Domain\Report\Month\MonthStays;
use Ritmo\Domain\Report\Month\Period;
use Ritmo\Domain\Support\Days;
use Ritmo\Tests\Support\Golden;

/**
 * desempenho_mes.calcular against MonthPerformance (made in the month, cancellations, stays).
 */
final class MonthPerformanceGoldenTest extends TestCase
{
    public function testMonthPerformanceMatchesCalcular(): void
    {
        $cases = ReportGolden::cases('desempenho_mes.calcular');
        self::assertCount(4, $cases);
        $lines = ReportGolden::lines(ReportGolden::rawBookings());
        foreach ($cases as $n => $case) {
            $month = $case['input']['mes'];
            $report = MonthPerformance::of($lines, $month, ReportGolden::instant($case['input']['ate']), ReportGolden::capacities(), ReportGolden::targets($month), ReportGolden::TIMEZONE);
            $e = $case['expected'];
            foreach ($e['feitas'] as $name => $made) {
                $scope = $name === 'Total' ? MonthReport::SITE : Golden::propertyId($name);
                $where = "case $n $name";
                BookingLineGoldenTest::assertTotals($made['geral'], $report->made[$scope]->overall, "$where overall");
                self::assertGroups($made['canal'], $report->made[$scope]->byChannel, "$where channel", static fn (string $k): string => ReportGolden::CHANNELS[$k]);
                self::assertGroups($made['quarto'], $report->made[$scope]->byRoom, "$where room", static fn (string $k): string => $k);
                self::assertGroups($made['semana'], $report->made[$scope]->byWeek, "$where week", static fn (string $k): int => (int) $k);
                self::assertGroups($made['mes_estadia'], $report->made[$scope]->byStayMonth, "$where stay month", static fn (string $k): string => $k);
                $c = $e['cancelamentos_no_mes'][$name];
                $actual = $report->cancellations[$scope];
                self::assertSame($c['quantidade'], $actual->count, "$where cancellations");
                self::assertEqualsWithDelta($c['receita'], $actual->revenue, Golden::AMOUNT_TOLERANCE, "$where cancelled revenue");
                self::assertSame($c['de_reservas_feitas_antes_do_mes'], $actual->ofBookingsMadeBefore, "$where made before");
            }
            foreach ($e['estadias'] as $name => $stays) {
                self::assertStays($stays, $report->stays[Golden::propertyId($name)], "case $n $name stays");
            }
        }
    }

    public function testACancellationAtExactlyTheEndInstantCountsDivergenceRpX3(): void
    {
        $raw = ['id' => 1, 'roomId' => ReportGolden::firstRoomRef(), 'status' => 'cancelled', 'channel' => 'booking', 'arrival' => '2026-12-10', 'departure' => '2026-12-12', 'price' => 100,
            'bookingTime' => '2026-11-02T12:00:00Z', 'cancelTime' => '2026-11-13T13:00:00Z'];
        $report = MonthPerformance::of(ReportGolden::lines([$raw]), '2026-11', ReportGolden::instant('2026-11-13T13:00:00+00:00'), ReportGolden::capacities(), [], ReportGolden::TIMEZONE);
        self::assertSame(1, $report->cancellations[MonthReport::SITE]->count);
    }

    public function testMonthsAreTheCurrentAndTheNext(): void
    {
        foreach (ReportGolden::cases('revisao_12m.meses') as $case) {
            self::assertSame($case['expected'], Period::following(Days::date($case['input']['hoje']), $case['input']['n']));
        }
    }

    public function testAMonthWithoutCapacityHasNoOccupancy(): void
    {
        $report = MonthPerformance::of([], '2026-11', ReportGolden::instant('2026-11-13T13:00:00+00:00'), ['p' => 0], [], ReportGolden::TIMEZONE);
        self::assertNull($report->stays['p']->occupancy);
        self::assertNull($report->stays['p']->adrNeeded);
        self::assertSame(0, $report->made[MonthReport::SITE]->overall->bookings);
    }

    /**
     * @param array<string, array<string, mixed>>         $expected
     * @param array<int|string, BookingTotals>            $actual
     * @param \Closure(string): (int|string)              $key
     */
    private static function assertGroups(array $expected, array $actual, string $where, \Closure $key): void
    {
        $mapped = [];
        foreach ($expected as $k => $totals) {
            $mapped[$key((string) $k)] = $totals;
        }
        self::assertEqualsCanonicalizing(array_keys($mapped), array_keys($actual), $where);
        foreach ($mapped as $k => $totals) {
            BookingLineGoldenTest::assertTotals($totals, $actual[$k], "$where $k");
        }
    }

    /**
     * @param array<string, array<string, mixed>> $e
     */
    public static function assertStays(array $e, MonthStays $s, string $where): void
    {
        $r = $e['realizado'];
        self::assertSame($r['dias'], $s->daysPast, "$where days past");
        self::assertSame($r['diarias'], $s->realisedNights, "$where realised nights");
        self::assertEqualsWithDelta($r['receita'], $s->realisedRevenue, Golden::AMOUNT_TOLERANCE, "$where realised revenue");
        self::assertSame(self::f($r['ocupacao']), $s->realisedOccupancy, "$where realised occupancy");
        BookingLineGoldenTest::assertAmount($r['adr'], $s->realisedAdr, "$where realised adr");
        BookingLineGoldenTest::assertAmount($r['revpar'], $s->realisedRevpar, "$where realised revpar");
        $a = $e['vendido_ate_o_fim'];
        self::assertSame($a['dias'], $s->daysAhead, "$where days ahead");
        self::assertSame($a['diarias'], $s->aheadNights, "$where ahead nights");
        self::assertEqualsWithDelta($a['receita'], $s->aheadRevenue, Golden::AMOUNT_TOLERANCE, "$where ahead revenue");
        self::assertSame(self::f($a['ocupacao']), $s->aheadOccupancy, "$where ahead occupancy");
        $m = $e['mes'];
        self::assertSame($m['diarias'], $s->nights, "$where nights");
        self::assertEqualsWithDelta($m['receita'], $s->revenue, Golden::AMOUNT_TOLERANCE, "$where revenue");
        self::assertSame(self::f($m['ocupacao']), $s->occupancy, "$where occupancy");
        BookingLineGoldenTest::assertAmount($m['adr'], $s->adr, "$where adr");
        BookingLineGoldenTest::assertAmount($m['minimo_dono'], $s->minimum, "$where minimum");
        BookingLineGoldenTest::assertAmount($m['falta_para_o_minimo'], $s->gapToMinimum, "$where gap");
        self::assertSame($m['diarias_livres'], $s->freeNights, "$where free nights");
        BookingLineGoldenTest::assertAmount($m['diaria_necessaria_nas_livres'], $s->adrNeeded, "$where adr needed");
    }

    private static function f(int|float|null $v): ?float
    {
        return $v === null ? null : (float) $v;
    }
}
