<?php

declare(strict_types=1);

namespace Ritmo\Tests\Unit\Domain\Operations;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Ritmo\Domain\Operations\MovementClassifier;
use Ritmo\Domain\Support\Days;
use Ritmo\Tests\Support\Fixtures;
use Ritmo\Tests\Support\Golden;

/**
 * Parity of the movement classification with the reference (beds24.movimento_de,
 * beds24.alterada_desde) and of the booking totals the alerts read (beds24.receita_por_noite),
 * on the goldens of tests/fixtures/golden/m3/ops/ (O49).
 */
final class MovementGoldenTest extends TestCase
{
    /**
     * @return array<string, array{array<string, mixed>, mixed}>
     */
    private static function cases(string $file): array
    {
        $cases = [];
        foreach (Fixtures::json('golden/m3/ops/' . $file)['cases'] as $case) {
            $cases[$case['name']] = [$case['input'], $case['expected']];
        }

        return $cases;
    }

    public static function movements(): array
    {
        return self::cases('beds24.movimento_de.json');
    }

    public static function changes(): array
    {
        return self::cases('beds24.alterada_desde.json');
    }

    public static function revenues(): array
    {
        return self::cases('beds24.receita_por_noite.json');
    }

    /**
     * @param array<string, mixed> $input
     * @param array<string, mixed> $expected
     */
    #[DataProvider('movements')]
    public function testTheKindOfAMovementMatchesTheReference(array $input, array $expected): void
    {
        $booking = ReferenceBookings::one($input['reserva']);
        $kind = MovementClassifier::classify($booking, new DateTimeImmutable($input['desde']));

        self::assertSame(ReferenceBookings::KINDS[$expected['tipo']], $kind->value);
        self::assertSame((string) $expected['id'], $booking->pmsRef);
        self::assertSame(ReferenceBookings::STATUSES[$expected['status']] ?? $expected['status'], $booking->status->value);
        self::assertSame($expected['chegada'], Days::key($booking->arrival));
        self::assertSame($expected['noites'], $booking->nights()->count());
        self::assertEqualsWithDelta($expected['receita'], $booking->total ?? 0.0, Golden::AMOUNT_TOLERANCE);
    }

    /**
     * @param array<string, mixed> $input
     */
    #[DataProvider('changes')]
    public function testChangedSinceMatchesTheReference(array $input, bool $expected): void
    {
        self::assertSame($expected, MovementClassifier::changedSince(ReferenceBookings::one($input['reserva']), new DateTimeImmutable($input['desde'])));
    }

    /**
     * @param array<string, mixed> $input
     * @param array<string, mixed> $expected
     */
    #[DataProvider('revenues')]
    public function testTheBookingTotalAndNightsMatchTheReference(array $input, array $expected): void
    {
        $booking = ReferenceBookings::one($input);

        self::assertSame($expected['chegada'], Days::key($booking->arrival));
        self::assertSame($expected['noites'], $booking->nights()->count());
        // price_missing_is_zero: the reference gives 0.0, the mapper null; both are "no value" to the alerts.
        self::assertEqualsWithDelta($expected['total'], $booking->total ?? 0.0, Golden::AMOUNT_TOLERANCE);
    }

    public function testTheManifestComesFromCommittedReferenceCode(): void
    {
        $manifest = Fixtures::json('golden/m3/ops/MANIFEST.json');

        self::assertFalse($manifest['engine_dirty']);
        foreach ($manifest['files'] as $file => $count) {
            self::assertCount($count, Fixtures::json('golden/m3/ops/' . $file)['cases'], $file);
        }
    }
}
