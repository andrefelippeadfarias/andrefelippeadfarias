<?php

declare(strict_types=1);

namespace Ritmo\Tests\Unit\Domain\Report;

use PHPUnit\Framework\TestCase;
use Ritmo\Domain\Report\Booking\BookingLine;
use Ritmo\Domain\Report\Booking\BookingTotals;
use Ritmo\Domain\Support\Days;
use Ritmo\Tests\Support\Golden;

/**
 * desempenho_mes.linha and desempenho_mes.agrega against BookingLine and BookingTotals.
 */
final class BookingLineGoldenTest extends TestCase
{
    public function testEveryBookingLineMatchesLinha(): void
    {
        $cases = ReportGolden::cases('desempenho_mes.linha');
        self::assertCount(40, $cases);
        foreach ($cases as $n => $case) {
            $line = ReportGolden::lines([$case['input']['booking']])[0];
            $e = $case['expected'];
            $where = "case $n";
            self::assertSame(Golden::propertyId($e['pousada']), $line->propertyId, $where);
            self::assertSame($e['quarto'], $line->roomCode, $where);
            self::assertSame($e['status'], $line->status->value, $where);
            self::assertSame(ReportGolden::CHANNELS[$e['canal']], $line->channel, $where);
            self::assertSame($e['chegada'], Days::key($line->arrival), $where);
            self::assertSame($e['noites'], $line->nights, $where);
            self::assertSame($e['un'], $line->units, $where);
            self::assertEqualsWithDelta($e['receita'], $line->revenue, Golden::AMOUNT_TOLERANCE, $where);
            self::assertEquals(ReportGolden::instant($e['feita']), $line->createdAt, $where);
            self::assertSame($e['antecedencia'], $line->leadDays, $where);
            if ($e['cancelada_em'] === '') {
                self::assertNull($line->cancelledAt, $where);
            } else {
                self::assertEquals(ReportGolden::instant($e['cancelada_em']), $line->cancelledAt, $where);
            }
        }
    }

    public function testTotalsMatchAgrega(): void
    {
        $cases = ReportGolden::cases('desempenho_mes.agrega');
        self::assertCount(4, $cases);
        foreach ($cases as $n => $case) {
            $totals = BookingTotals::of(ReportGolden::counted($case['input']['bookings']));
            self::assertTotals($case['expected'], $totals, "case $n");
        }
    }

    public function testDirectBookingsAreSplitByTheirOrigin(): void
    {
        $raw = ['id' => 1, 'roomId' => ReportGolden::firstRoomRef(), 'status' => 'confirmed', 'channel' => '', 'referer' => 'API', 'arrival' => '2026-12-01', 'departure' => '2026-12-02', 'price' => 10];
        self::assertSame(BookingLine::DIRECT_API, ReportGolden::lines([$raw])[0]->channel);
        self::assertSame(BookingLine::DIRECT_MANUAL, ReportGolden::lines([['referer' => 'api'] + $raw])[0]->channel);
        self::assertSame('vrbo', ReportGolden::lines([['channel' => 'VRBO'] + $raw])[0]->channel);
    }

    public function testABookingWithoutCreationInstantHasNoLeadTimeAndStaysOutOfTheMean(): void
    {
        $base = ['roomId' => ReportGolden::firstRoomRef(), 'status' => 'confirmed', 'channel' => 'booking', 'arrival' => '2026-12-10', 'departure' => '2026-12-12', 'price' => 100];
        $lines = ReportGolden::lines([
            ['id' => 1, 'bookingTime' => '2026-12-01T12:00:00Z'] + $base,
            ['id' => 2] + $base,
        ]);
        self::assertNull($lines[1]->leadDays);
        self::assertSame(9.0, BookingTotals::of($lines)->meanLeadDays);
    }

    /**
     * @param array<string, mixed> $e
     */
    public static function assertTotals(array $e, BookingTotals $t, string $where): void
    {
        self::assertSame($e['reservas'], $t->bookings, "$where: bookings");
        self::assertSame($e['canceladas'], $t->cancelled, "$where: cancelled");
        self::assertSame($e['ativas'], $t->active, "$where: active");
        self::assertSame($e['diarias'], $t->roomNights, "$where: room nights");
        self::assertEqualsWithDelta($e['receita'], $t->revenue, Golden::AMOUNT_TOLERANCE, "$where: revenue");
        self::assertAmount($e['adr'], $t->adr, "$where: adr");
        self::assertSame($e['antecedencia'] === null ? null : (float) $e['antecedencia'], $t->meanLeadDays, "$where: lead");
        self::assertSame($e['estadia'] === null ? null : (float) $e['estadia'], $t->meanStayNights, "$where: stay");
        if (array_key_exists('receita_cancelada', $e)) {
            self::assertEqualsWithDelta($e['receita_cancelada'], $t->cancelledRevenue, Golden::AMOUNT_TOLERANCE, "$where: cancelled revenue");
            self::assertSame($e['canceladas_sem_valor'], $t->cancelledWithoutValue, "$where: cancelled without value");
        }
    }

    public static function assertAmount(int|float|null $expected, ?float $actual, string $where): void
    {
        if ($expected === null) {
            self::assertNull($actual, $where);

            return;
        }
        self::assertNotNull($actual, $where);
        self::assertEqualsWithDelta($expected, $actual, Golden::AMOUNT_TOLERANCE, $where);
    }
}
