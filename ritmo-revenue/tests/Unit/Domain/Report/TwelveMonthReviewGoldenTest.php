<?php

declare(strict_types=1);

namespace Ritmo\Tests\Unit\Domain\Report;

use PHPUnit\Framework\TestCase;
use Ritmo\Domain\Report\Booking\BookingLine;
use Ritmo\Domain\Report\Review\MarketMonth;
use Ritmo\Domain\Report\Review\Reading;
use Ritmo\Domain\Report\Review\ReviewConfig;
use Ritmo\Domain\Report\Review\ReviewDue;
use Ritmo\Domain\Report\Review\ReviewResult;
use Ritmo\Domain\Report\Review\ReviewSnapshot;
use Ritmo\Domain\Report\Review\TwelveMonthReview;
use Ritmo\Domain\Support\Days;
use Ritmo\Tests\Support\Golden;

/**
 * revisao_12m.devida and revisao_12m.rodar against ReviewDue and TwelveMonthReview.
 */
final class TwelveMonthReviewGoldenTest extends TestCase
{
    private const READINGS = ['cedo' => Reading::Early, 'à frente' => Reading::Ahead, 'no ritmo' => Reading::OnPace, 'atrás' => Reading::Behind];

    public function testDueMatchesDevida(): void
    {
        foreach (ReportGolden::cases('revisao_12m.devida') as $n => $case) {
            $history = $case['input']['hist']['revisoes'];
            $last = $history === [] ? null : Days::date(end($history)['hoje']);
            $due = ReviewDue::of(Days::date($case['input']['hoje']), $last, 15);
            self::assertSame($case['expected']['due'], $due->due, "case $n");
            if ($last !== null) {
                self::assertStringContainsString('(' . $due->daysSinceLast . ' dias)', $case['expected']['why'], "case $n");
            }
        }
    }

    public function testTheReviewMatchesRodarWithAndWithoutAPreviousSnapshot(): void
    {
        $cases = ReportGolden::cases('revisao_12m.rodar');
        self::assertCount(2, $cases);
        $all = ReportGolden::lines(ReportGolden::rawBookings());
        $first = self::review(array_values(array_filter($all, static fn (BookingLine $l): bool => $l->createdAt < ReportGolden::instant('2026-10-29T12:00:00Z'))), $cases[0]['input'], null);
        self::assertRows($cases[0]['expected'], $first, 'first review');
        self::assertNull($first->previousDay);

        $snapshot = ReviewSnapshot::fromArray(ReviewSnapshot::of($first)->toArray());
        $second = self::review($all, $cases[1]['input'], $snapshot);
        self::assertSame('2026-10-29', Days::key($second->previousDay));
        self::assertRows($cases[1]['expected'], $second, 'second review');
    }

    public function testASnapshotOfTodayIsNotTheOneToCompareWith(): void
    {
        $input = ReportGolden::cases('revisao_12m.rodar')[0]['input'];
        $today = self::review([], $input, null);
        $again = self::review([], $input, ReviewSnapshot::of($today));
        self::assertNull($again->previousDay);
        self::assertNull($again->rows[0]->pickupSinceLast);
    }

    public function testReadingsAtTheirBoundaries(): void
    {
        $config = new ReviewConfig();
        self::assertSame(Reading::Early, $config->reading(0.5, 4.9));
        self::assertSame(Reading::Ahead, $config->reading(0.12, 10.0));
        self::assertSame(Reading::Behind, $config->reading(0.08, 10.0));
        self::assertSame(Reading::OnPace, $config->reading(0.1, 10.0));
    }

    /**
     * @param list<BookingLine>    $lines
     * @param array<string, mixed> $input
     */
    private static function review(array $lines, array $input, ?ReviewSnapshot $previous): ReviewResult
    {
        $market = [];
        foreach ($input['market'] as $month => $m) {
            $market[$month] = new MarketMonth((float) $m['mercado_vendido'], (float) $m['mercado_ano_passado']);
        }
        $prices = [];
        foreach ($input['prices'] as [$name, $month, $value]) {
            $prices[Golden::propertyId($name)][$month] = (float) $value;
        }

        return TwelveMonthReview::of($lines, Days::date($input['hoje']), ReportGolden::instant($input['ate']), ReportGolden::capacities(), ReportGolden::allTargets(), $market, $prices, $previous, new ReviewConfig(), ReportGolden::TIMEZONE);
    }

    /**
     * @param array<string, mixed> $expected
     */
    private static function assertRows(array $expected, ReviewResult $actual, string $where): void
    {
        self::assertCount(count($expected['linhas']), $actual->rows, $where);
        foreach ($expected['linhas'] as $i => $e) {
            $r = $actual->rows[$i];
            $at = "$where row $i ({$e['mes']} {$e['pousada']})";
            self::assertSame($e['mes'], $r->month, $at);
            self::assertSame(Golden::propertyId($e['pousada']), $r->propertyId, $at);
            self::assertEqualsWithDelta($e['vendido'], $r->soldRevenue, Golden::AMOUNT_TOLERANCE, "$at sold");
            self::assertSame((float) $e['ocupacao_vendida'], $r->soldOccupancy, "$at occupancy");
            BookingLineGoldenTest::assertAmount($e['diaria_media'], $r->adr, "$at adr");
            BookingLineGoldenTest::assertAmount($e['minimo_dono'], $r->minimum, "$at minimum");
            BookingLineGoldenTest::assertAmount($e['realista'], $r->realistic, "$at realistic");
            self::assertSame($e['atingido_minimo'] === null ? null : (float) $e['atingido_minimo'], $r->achieved, "$at achieved");
            BookingLineGoldenTest::assertAmount($e['pickup_desde_ultima'], $r->pickupSinceLast, "$at pickup");
            self::assertSame($e['mercado_vendido'], $r->marketSoldPercent, "$at market");
            self::assertSame($e['mercado_ano_passado'], $r->marketLastYearPercent, "$at market last year");
            self::assertSame($e['leitura'] === null ? null : self::READINGS[$e['leitura']], $r->reading, "$at reading");
            BookingLineGoldenTest::assertAmount($e['preco_medio_suites'], $r->averagePrice, "$at price");
            self::assertSame($e['preco_var_desde_ultima'] === null ? null : (float) $e['preco_var_desde_ultima'], $r->priceChange, "$at price change");
        }
    }
}
