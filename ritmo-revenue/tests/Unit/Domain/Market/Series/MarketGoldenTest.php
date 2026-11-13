<?php

declare(strict_types=1);

namespace Ritmo\Tests\Unit\Domain\Market\Series;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Ritmo\Domain\Market\Series\BandPosition;
use Ritmo\Domain\Market\Series\ComparableFactor;
use Ritmo\Domain\Market\Series\NightSeries;
use Ritmo\Domain\Market\Series\Percentile;
use Ritmo\Domain\Market\Series\PricePosition;
use Ritmo\Domain\Market\Series\RoomBasis;
use Ritmo\Domain\Market\Series\SeriesBuilder;
use Ritmo\Domain\Market\Series\SeriesDefinition;
use Ritmo\Domain\Market\Series\StaySeries;
use Ritmo\Domain\Market\Series\StayValues;
use Ritmo\Domain\Market\Series\TypeTable;
use Ritmo\Domain\Support\Days;
use Ritmo\Tests\Support\Golden;

/**
 * Parity of the market series with the reference engine (faixas_dono, goldens in
 * tests/fixtures/golden/m4/market): amounts within 0.01, percentiles, counts and origins exact.
 * The one deliberate divergence, MK-X1 (newest price per competitor instead of the first file's
 * stay), is applied in MarketGoldenInputs::sweepSamples() only.
 */
final class MarketGoldenTest extends TestCase
{
    public function testPercentilesMatchTheReference(): void
    {
        $cases = MarketGoldenInputs::cases('faixas_dono.quantil');
        self::assertCount(45, $cases);
        foreach ($cases as $case) {
            self::assertEqualsWithDelta($case['expected'], Percentile::of($case['input']['v'], $case['input']['p']), 1e-9);
        }
    }

    public function testComparableFactorsMatchTheReference(): void
    {
        $cases = MarketGoldenInputs::cases('faixas_dono.r_hidro');
        self::assertCount(7, $cases);
        foreach ($cases as $case) {
            $hotel = $case['input']['competitor'];
            $factor = isset($hotel['r_hidro']) ? (float) $hotel['r_hidro'] : null;
            self::assertEqualsWithDelta($case['expected'], ComparableFactor::of($factor, MarketGoldenInputs::source($hotel), (float) $case['input']['default'], 2.0), 0.0001);
        }
    }

    public function testStayValuesMatchTheReferenceInTheStaysOrder(): void
    {
        $catalog = MarketGoldenInputs::cases('faixas_dono.construir')[0]['input']['catalog'];
        $catalogue = MarketGoldenInputs::catalogue($catalog);
        $ids = MarketGoldenInputs::ids($catalogue);
        $cases = MarketGoldenInputs::cases('faixas_dono.estadia_por_noite');
        self::assertCount(14, $cases);
        foreach ($cases as $case) {
            $tags = $case['input']['group'] === 'peer' ? ['peer'] : [];
            $samples = MarketGoldenInputs::samples($case['input']['stay'], $ids);
            foreach (['comparable' => RoomBasis::Comparable, 'cheapest' => RoomBasis::Cheapest] as $field => $basis) {
                $values = StayValues::of($samples, $catalogue, new SeriesDefinition('s_' . $field, $basis, $tags), 1.395, 2.0);
                self::assertCount(count($case['expected'][$field]), $values);
                foreach ($case['expected'][$field] as $i => $expected) {
                    self::assertEqualsWithDelta($expected, $values[$i], Golden::AMOUNT_TOLERANCE);
                }
            }
        }
    }

    public function testStaySeriesAndTypeTablesMatchTheReference(): void
    {
        $cases = MarketGoldenInputs::cases('faixas_dono.construir');
        self::assertCount(2, $cases);
        foreach ($cases as $case) {
            $built = $this->build($case);
            $expected = $case['expected'];
            self::assertCount(count($expected['estadias']), $built['comparable'], 'min_n ' . $case['input']['min_n']);
            foreach ($expected['estadias'] as $i => $stay) {
                $comparable = $built['comparable'][$i];
                $cheapest = $built['cheapest'][$i];
                self::assertSame($stay['checkin'], Days::key($comparable->checkIn));
                self::assertSame($stay['checkout'], Days::key($comparable->checkOut()));
                self::assertSame($stay['tipo'], $comparable->type);
                self::assertSame($stay['n'], $comparable->n);
                self::assertSame($stay['hidro'], $comparable->percentiles);
                self::assertSame($stay['lista'], $cheapest->percentiles);
                self::assertCount(count($stay['valores_hidro']), $comparable->values);
                foreach ($stay['valores_hidro'] as $j => $value) {
                    self::assertEqualsWithDelta($value, $comparable->values[$j], Golden::AMOUNT_TOLERANCE);
                }
            }
            foreach ($expected['por_tipo'] as $type => $summary) {
                $table = TypeTable::of(self::ofType($built['comparable'], $type), [25, 50, 65, 75, 90], 4, 40);
                $list = TypeTable::of(self::ofType($built['cheapest'], $type), [25, 50, 65, 75, 90], 4, 40);
                self::assertSame($summary['estadias'], $table->stays);
                self::assertSame($summary['meses'], $table->months);
                self::assertSame($summary['concorrentes'], $table->competitors);
                self::assertSame($summary['de'], Days::key($table->from));
                self::assertSame($summary['ate'], Days::key($table->to));
                self::assertSame($summary['amostra_pequena'], $table->smallSample);
                self::assertSame($summary['hidro'], $table->values);
                self::assertSame($summary['lista'], $list->values);
            }
        }
    }

    public function testMonthlyMediansMatchTheReference(): void
    {
        $cases = MarketGoldenInputs::cases('faixas_dono.mediana_por_mes');
        self::assertCount(30, $cases);
        foreach ($cases as $case) {
            $field = $case['input']['field'];
            $stays = array_map(
                static fn (array $s): StaySeries => new StaySeries($field, Days::date($s['checkin']), 1, 'any', 1, $s[$field], [], new DateTimeImmutable(), new DateTimeImmutable()),
                $case['input']['stays']
            );
            self::assertSame($case['expected'], TypeTable::value($stays, (string) $case['input']['p']));
        }
    }

    public function testNightValuesAndTheirOriginMatchTheReference(): void
    {
        $built = $this->build(MarketGoldenInputs::cases('faixas_dono.construir')[0]);
        $nightSeries = new NightSeries(40, 3);
        $cases = MarketGoldenInputs::cases('faixas_dono.regua_noite');
        self::assertCount(89, $cases);
        $origins = [];
        foreach ($cases as $case) {
            $type = $case['input']['type'];
            $sameType = self::ofType($built['comparable'], $type);
            $table = TypeTable::of($sameType, [25, 50, 65, 75, 90], 4, 40);
            $value = $nightSeries->of(Days::date($case['input']['night']), $sameType, $table);
            self::assertNotNull($value, $case['input']['night']);
            self::assertSame($case['expected']['values'], $value->values, $case['input']['night']);
            self::assertSame($case['expected']['origin'], $value->origin, $case['input']['night']);
            $origins[$value->origin] = true;
        }
        self::assertCount(4, $origins, 'the goldens cover the four origins');
    }

    public function testThePercentileOfAPriceMatchesTheReference(): void
    {
        $cases = MarketGoldenInputs::cases('faixas_dono.percentil_de');
        self::assertCount(7, $cases);
        foreach ($cases as $case) {
            $result = PricePosition::percentile((float) $case['input']['x'], $case['input']['v']);
            if ($case['expected'] === Golden::NAN) {
                self::assertNull($result);
            } else {
                self::assertEqualsWithDelta($case['expected'], $result, 1e-9);
            }
        }
    }

    public function testTheBandOfAPriceMatchesTheReference(): void
    {
        $cases = MarketGoldenInputs::cases('faixas_dono.posicao');
        self::assertCount(20, $cases);
        foreach ($cases as $case) {
            $position = PricePosition::band((float) $case['input']['value'], $case['input']['bands'], (float) $case['input']['tolerance']);
            self::assertSame($case['expected'], self::referenceText($position));
        }
    }

    /**
     * @param array<string, mixed> $case a construir case
     *
     * @return array<string, list<StaySeries>>
     */
    private function build(array $case): array
    {
        $catalogue = MarketGoldenInputs::catalogue($case['input']['catalog']);
        $types = $case['input']['types'];
        $typeOf = static function (DateTimeImmutable $checkIn, int $nights) use ($types): ?string {
            $type = $types[Days::key($checkIn) . '/' . Days::key(Days::add($checkIn, $nights))] ?? null;

            return $type === 'ignorar' ? null : $type;
        };
        $builder = new SeriesBuilder(MarketGoldenInputs::config((int) $case['input']['min_n']), $catalogue, $typeOf);

        return $builder->build(MarketGoldenInputs::sweepSamples(MarketGoldenInputs::ids($catalogue)), MarketGoldenInputs::window());
    }

    /**
     * @param list<StaySeries> $stays
     *
     * @return list<StaySeries>
     */
    private static function ofType(array $stays, string $type): array
    {
        return array_values(array_filter($stays, static fn (StaySeries $s): bool => $s->type === $type));
    }

    private static function referenceText(BandPosition $position): string
    {
        return match ($position->kind) {
            BandPosition::NEAR => '≈' . $position->bands[0],
            BandPosition::BELOW => 'abaixo da ' . $position->bands[0],
            BandPosition::ABOVE => 'acima da ' . $position->bands[0],
            BandPosition::BETWEEN => 'entre ' . $position->bands[0] . ' e ' . $position->bands[1],
            default => '—',
        };
    }
}
