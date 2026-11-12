<?php

declare(strict_types=1);

namespace Ritmo\Tests\Unit\Domain\Pricing\Ladder;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Ritmo\Domain\Calendar\HolidayCalendar;
use Ritmo\Domain\Market\PriceFactor;
use Ritmo\Domain\Model\BookingRecord;
use Ritmo\Domain\Model\BookingStatus;
use Ritmo\Domain\Pricing\Ladder\BatchImportDetector;
use Ritmo\Domain\Pricing\Ladder\MarketTopTable;
use Ritmo\Domain\Pricing\Ladder\ProportionalLadder;
use Ritmo\Domain\Pricing\Ladder\ProportionalMove;
use Ritmo\Domain\Support\Days;
use Ritmo\Tests\Support\Golden;

/**
 * Parity of the proportional ladder mode with escada_reservas.py.
 */
final class ProportionalLadderGoldenTest extends TestCase
{
    private const MARKET = ['periodos' => [], 'referencias_topo' => []];

    #[DataProvider('lines')]
    public function testProportional(array $in, float $expected): void
    {
        self::assertEqualsWithDelta($expected, ProportionalLadder::proportional($in['current'], $in['target'], $in['units'], $in['sold_before'], $in['sold_after']), Golden::AMOUNT_TOLERANCE);
    }

    #[DataProvider('steps')]
    public function testFallbackStepAndCeiling(array $in, array $expected): void
    {
        $ladder = self::ladder(self::MARKET);
        $night = Reference::date($in['night']);

        self::assertEqualsWithDelta($expected['new'], $ladder->fallbackStep($in['room'], $night, $in['current'], $in['bookings'], Reference::date($in['today'])), Golden::AMOUNT_TOLERANCE);
        self::assertEqualsWithDelta($expected['ceiling'], $ladder->ceiling($in['room'], $night), Golden::AMOUNT_TOLERANCE);
    }

    #[DataProvider('ownerFloors')]
    public function testOwnerFloor(array $in, float $expected): void
    {
        self::assertEqualsWithDelta($expected, self::ladder(self::MARKET)->ownerFloor($in['room'], Reference::date($in['night'])), Golden::AMOUNT_TOLERANCE);
    }

    #[DataProvider('topCases')]
    public function testMarketTop(array $in, array $expected): void
    {
        $top = self::topTable($in['market'])->topFor(Reference::date($in['night']));

        self::assertSame($expected[0] === null, $top === null);
        if ($top !== null) {
            self::assertEqualsWithDelta($expected[0], $top['top'], Golden::AMOUNT_TOLERANCE);
        }
    }

    #[DataProvider('targets')]
    public function testTarget(array $in, array $expected): void
    {
        $target = self::ladder($in['market'])->target($in['room'], Reference::date($in['night']));

        $expected[0] === null ? self::assertNull($target) : self::assertEqualsWithDelta($expected[0], $target, Golden::AMOUNT_TOLERANCE);
    }

    #[DataProvider('batches')]
    public function testBatchNights(array $in, array $expected): void
    {
        $bookings = [];
        foreach ($in['bookings'] as $i => $b) {
            $arrival = Reference::date($b['check_in']);
            $bookings[] = new BookingRecord(
                'b' . $i,
                $b['room'],
                $arrival,
                Days::add($arrival, $b['no_of_days']),
                $b['booking_status'] === 'booked' ? BookingStatus::Confirmed : BookingStatus::Cancelled,
                'booking',
                'channel',
                null,
                Reference::instant($b['booked_date']),
                null,
                null,
            );
        }
        $since = $in['since'] === null ? null : Reference::instant($in['since']);
        $actual = (new BatchImportDetector($in['min_same_minute']))->batchNights($bookings, $since);
        ksort($actual);

        self::assertSame($expected, $actual);
    }

    #[DataProvider('plans')]
    public function testPlan(array $in, array $expected): void
    {
        $read = static function (array $calendars): array {
            $out = [];
            foreach ($calendars as $room => $nights) {
                $out[$room] = [];
                foreach ($nights as $night => $row) {
                    $out[$room][$night] = Reference::priceNight($room, $row);
                }
            }

            return $out;
        };
        $moves = self::ladder($in['market'])->plan($read($in['calendars_before']), $read($in['calendars_after']), Reference::date($in['today']), $in['batch_nights']);

        self::assertCount(count($expected), $moves);
        foreach ($expected as $i => $item) {
            $move = $moves[$i];
            self::assertInstanceOf(ProportionalMove::class, $move);
            self::assertSame($item['quarto'], $move->roomCode);
            self::assertSame($item['data'], $move->night->format('Y-m-d'));
            self::assertEqualsWithDelta($item['min_price'], $move->minPrice, Golden::AMOUNT_TOLERANCE);
            isset($item['max_price']) ? self::assertEqualsWithDelta($item['max_price'], $move->maxPrice, Golden::AMOUNT_TOLERANCE) : self::assertNull($move->maxPrice);
            self::assertEqualsWithDelta($item['preco_antes'], $move->priceBefore, Golden::AMOUNT_TOLERANCE);
        }
    }

    public static function lines(): array
    {
        return Reference::provider('escada_reservas.proporcional');
    }

    public static function steps(): array
    {
        return Reference::provider('escada_reservas.passo');
    }

    public static function ownerFloors(): array
    {
        return Reference::provider('escada_reservas.piso_dono');
    }

    public static function topCases(): array
    {
        return Reference::provider('escada_reservas.topo_publico');
    }

    public static function targets(): array
    {
        return Reference::provider('escada_reservas.alvo');
    }

    public static function batches(): array
    {
        return Reference::provider('escada_reservas.lotes');
    }

    public static function plans(): array
    {
        return Reference::provider('escada_reservas.plano');
    }

    /**
     * @param array<string, mixed> $market
     */
    private static function ladder(array $market): ProportionalLadder
    {
        $rules = Reference::rules();

        return new ProportionalLadder(Reference::strategy(), Reference::rooms(), $rules->ownerFloors(), new PriceFactor($rules), new HolidayCalendar($rules), $rules->weekendDays(), self::topTable($market));
    }

    /**
     * @param array<string, mixed> $market
     */
    private static function topTable(array $market): MarketTopTable
    {
        $periods = array_map(static fn (array $p): array => ['label' => $p['rotulo'], 'nights' => $p['datas'], 'top' => $p['topo_a']], $market['periodos']);
        $references = array_map(static fn (array $r): array => ['label' => $r['rotulo'], 'night' => $r['data'], 'weekend' => $r['tipo'] === 'fds', 'top' => $r['topo_a']], $market['referencias_topo']);

        return new MarketTopTable($periods, $references, Reference::rules()->weekendDays());
    }
}
