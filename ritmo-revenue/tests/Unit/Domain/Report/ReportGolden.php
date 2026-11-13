<?php

declare(strict_types=1);

namespace Ritmo\Tests\Unit\Domain\Report;

use DateTimeImmutable;
use Ritmo\Connectors\Beds24\BookingMapper;
use Ritmo\Domain\Report\Booking\BookingLine;
use Ritmo\Domain\Report\Booking\BookingLines;
use Ritmo\Domain\Report\Month\MonthTarget;
use Ritmo\Tests\Support\Fixtures;
use Ritmo\Tests\Support\Golden;

/**
 * The golden cases of the reports area (tests/fixtures/golden/m4/reports, written by
 * tests/fixtures/tools/golden_reports.py) and the translation of the reference's vocabulary and
 * inputs into the plugin's types. Owner names and ids appear only here, in the fixtures.
 */
final class ReportGolden
{
    public const DIR = 'golden/m4/reports/';
    public const TIMEZONE = 'America/Sao_Paulo';

    /** The reference's channel labels => the plugin's channel keys. */
    public const CHANNELS = [
        'Booking' => 'booking',
        'Airbnb' => 'airbnb',
        'Expedia' => 'expedia',
        'direto (API/site)' => BookingLine::DIRECT_API,
        'direto (lançado à mão)' => BookingLine::DIRECT_MANUAL,
    ];

    /** @var list<array<string, mixed>>|null */
    private static ?array $bookings = null;

    private function __construct()
    {
    }

    /**
     * @return list<array{input: array<string, mixed>, expected: mixed}>
     */
    public static function cases(string $function): array
    {
        return Fixtures::json(self::DIR . $function . '.json')['cases'];
    }

    /**
     * @return list<array<string, mixed>> the 420 invented Beds24 bookings of the frozen input
     */
    public static function rawBookings(): array
    {
        return self::$bookings ??= Fixtures::json(self::DIR . 'frozen/bookings_420.json');
    }

    /**
     * @param list<array<string, mixed>> $raw Beds24 bookings
     *
     * @return list<BookingLine> every line (counted or not), in the order given
     */
    public static function lines(array $raw): array
    {
        $mapper = self::mapper();
        $rooms = self::roomProperty();

        return array_map(static fn (array $b): BookingLine => BookingLine::of($mapper->map($b), $rooms, self::TIMEZONE), $raw);
    }

    /**
     * @param list<array<string, mixed>> $raw
     *
     * @return list<BookingLine> the counted lines only
     */
    public static function counted(array $raw): array
    {
        $mapper = self::mapper();

        return BookingLines::of(array_map(static fn (array $b) => $mapper->map($b), $raw), self::roomProperty(), self::TIMEZONE);
    }

    public static function mapper(): BookingMapper
    {
        $map = [];
        foreach (Fixtures::parityPreset()->roomTypes as $room) {
            $map[$room->pmsRef] = $room->code;
        }

        return new BookingMapper($map);
    }

    /**
     * @return array<string, string> room code => property id
     */
    public static function roomProperty(): array
    {
        $out = [];
        foreach (Fixtures::parityPreset()->roomTypes as $room) {
            $out[$room->code] = $room->propertyId;
        }

        return $out;
    }

    /**
     * @return array<string, int> property id => units, in the reference's order (the manifest's)
     */
    public static function capacities(): array
    {
        $out = [];
        foreach (Golden::manifest()['vocabulary']['property'] as $id) {
            $out[$id] = array_sum(array_map(static fn ($r): int => $r->units, Fixtures::parityPreset()->roomTypesOf($id)));
        }

        return $out;
    }

    /**
     * The invented monthly targets of the frozen input, for one month.
     *
     * @return array<string, MonthTarget> property id => targets
     */
    public static function targets(string $month): array
    {
        $all = Fixtures::json(self::DIR . 'frozen/metas_faturamento.json')['por_pousada'][$month] ?? [];
        $out = [];
        foreach ($all as $name => $target) {
            $out[$name] = new MonthTarget(
                isset($target['minimo_dono']) ? (float) $target['minimo_dono'] : null,
                isset($target['realista']) ? (float) $target['realista'] : null,
            );
        }

        return $out;
    }

    /**
     * @return array<string, array<string, MonthTarget>> month => property id => targets
     */
    public static function allTargets(): array
    {
        $out = [];
        foreach (array_keys(Fixtures::json(self::DIR . 'frozen/metas_faturamento.json')['por_pousada']) as $month) {
            $out[$month] = self::targets($month);
        }

        return $out;
    }

    /**
     * The PMS id of the first room of the parity preset (the rooms of the invented bookings).
     */
    public static function firstRoomRef(): string
    {
        return Fixtures::parityPreset()->roomTypes[0]->pmsRef;
    }

    public static function instant(string $iso): DateTimeImmutable
    {
        return new DateTimeImmutable($iso);
    }

    public static function name(string $propertyId): string
    {
        return array_search($propertyId, Golden::manifest()['vocabulary']['property'], true) ?: $propertyId;
    }
}
