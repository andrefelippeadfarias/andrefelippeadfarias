<?php

declare(strict_types=1);

namespace Ritmo\Tests\Unit\Domain\Alerts;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Ritmo\Domain\Alerts\BelowExpected;
use Ritmo\Domain\Alerts\BelowExpectedConfig;
use Ritmo\Domain\Alerts\BelowExpectedFinding;
use Ritmo\Domain\Alerts\PriceBasis;
use Ritmo\Domain\Support\Days;
use Ritmo\Tests\Support\Fixtures;
use Ritmo\Tests\Support\Golden;
use Ritmo\Tests\Unit\Domain\Operations\ReferenceBookings;

/**
 * Parity of the bookings below expected with the reference (reservas_abaixo.avaliar) on the 13
 * goldens of tests/fixtures/golden/m3/ops/ (O30, O49): ids, room, arrival, nights, total and
 * expected within 0.01, ratio exact to 3 decimals, origin, order.
 *
 * Deliberate differences: the plugin keeps the booking's own channel where the reference writes
 * "direct" for every channel but its two OTAs (the factor is the same: a channel without a factor
 * takes the default, the reference's "direct" factor), and BookingRecord has no channel reference
 * yet (operations spec D9), so "booking_id" is not compared. The rate description is never read.
 */
final class BelowExpectedGoldenTest extends TestCase
{
    /** The markers of the property ids in the cases => the keys of their factor tables. */
    private const PROPERTIES = ['8001' => 'prop-a', '8002' => 'prop-b'];

    /**
     * @return array<string, array{array<string, mixed>, list<array<string, mixed>>}>
     */
    public static function cases(): array
    {
        $cases = [];
        foreach (Fixtures::json('golden/m3/ops/reservas_abaixo.avaliar.json')['cases'] as $case) {
            $cases[$case['name']] = [$case['input'], $case['expected']];
        }

        return $cases;
    }

    /**
     * @param array<string, mixed>       $input
     * @param list<array<string, mixed>> $expected
     */
    #[DataProvider('cases')]
    public function testTheFindingsMatchTheReference(array $input, array $expected): void
    {
        $prices = [];
        foreach ($input['precos'] as [$roomId, $night, $price]) {
            $prices[ReferenceBookings::ROOMS[$roomId]][$night] = (float) $price;
        }
        $check = new BelowExpected(new BelowExpectedConfig(true, $input['alerta'], 0.5, 1.0, PriceBasis::Current, $input['fatores']));
        $found = [];
        foreach (self::PROPERTIES as $marker => $property) {
            $bookings = array_values(array_filter($input['reservas'], static fn (array $b): bool => $b['propertyId'] === (string) $marker));
            $found = [...$found, ...$check->evaluate(ReferenceBookings::all($bookings), $prices, $property)];
        }
        usort($found, static fn (BelowExpectedFinding $a, BelowExpectedFinding $b): int => $a->ratio <=> $b->ratio);

        self::assertCount(count($expected), $found);
        foreach ($expected as $i => $row) {
            $f = $found[$i];
            self::assertSame((string) $row['id'], $f->booking->pmsRef);
            self::assertSame($row['quarto'], $f->booking->roomCode);
            self::assertSame($row['chegada'], Days::key($f->booking->arrival));
            self::assertSame($row['noites'], $f->nights);
            self::assertEqualsWithDelta($row['valor'], $f->total, Golden::AMOUNT_TOLERANCE);
            self::assertEqualsWithDelta($row['esperado'], $f->expected, Golden::AMOUNT_TOLERANCE);
            self::assertSame($row['recebe_do_esperado'], $f->ratio);
            self::assertSame($row['lancado_por'], $f->booking->origin);
            if (in_array($row['canal'], ['booking', 'airbnb'], true)) {
                self::assertSame($row['canal'], $f->booking->channel);
            }
        }
    }
}
