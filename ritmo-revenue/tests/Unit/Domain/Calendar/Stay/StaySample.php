<?php

declare(strict_types=1);

namespace Ritmo\Tests\Unit\Domain\Calendar\Stay;

use Ritmo\Domain\Calendar\HolidayCalendar;
use Ritmo\Domain\Calendar\Stay\InventoryAudit;
use Ritmo\Domain\Calendar\Stay\StayConfig;
use Ritmo\Domain\Calendar\Stay\StayRules;
use Ritmo\Domain\Calendar\Stay\StaySyncCapability;
use Ritmo\Domain\Calendar\Stay\StaySyncField;
use Ritmo\Domain\Model\InventoryNight;
use Ritmo\Domain\Model\Restriction;
use Ritmo\Domain\Model\RoomType;
use Ritmo\Domain\Support\Days;
use Ritmo\Tests\Unit\Domain\EngineSample;

/**
 * Invented stay rules for the unit tests: the sample rule set (holiday "Spring fair" 18-21 March
 * 2027 with a stay of 2, "Local week" 5-7 April 2027 without one), the rooms Deluxe (4 units) and
 * Standard (6) of the property "inn" and Cottage (1) of the property "annex". 1 March 2027 is a
 * Monday.
 */
final class StaySample
{
    /** Arrival rule: 1 night on Sunday, Tuesday and Thursday, 2 on the other days. */
    public const ARRIVAL = [
        'enabled' => true, 'valid_from' => '2027-01-01',
        'by_weekday' => ['0' => 2, '1' => 1, '2' => 2, '3' => 1, '4' => 2, '5' => 2, '6' => 1],
    ];

    /** Saturday closed for arrival and departure, own stay 2, orphan side on. */
    public const SATURDAY = [
        'weekday' => 5, 'restriction' => 'no_checkin_checkout', 'min_stay' => 2,
        'orphan_side' => ['enabled' => true],
    ];

    /**
     * @param array<string, mixed> $stay the "stay" section
     */
    public static function rules(array $stay = []): StayRules
    {
        return new StayRules(StayConfig::fromArray($stay), new HolidayCalendar(EngineSample::rules()), self::rooms());
    }

    /**
     * @param array<string, mixed> $stay
     */
    public static function audit(array $stay = [], ?StaySyncCapability $capability = null): InventoryAudit
    {
        return new InventoryAudit(self::rules($stay), $capability ?? new StaySyncCapability(StaySyncField::MinStay, StaySyncField::ArrivalDeparture));
    }

    /**
     * @return list<RoomType>
     */
    public static function rooms(): array
    {
        return [
            EngineSample::deluxe(),
            EngineSample::standard(),
            new RoomType('annex', 'Cottage', 'Garden cottage', 1, 'pms-3', 'pricing-3', 700.0, 400.0, 2000.0, 450.0),
        ];
    }

    /**
     * Nights of one room from a compact table: 'YYYY-MM-DD' => [available, minStay, restriction, maxStay].
     *
     * @param array<string, array{0: int, 1?: int|null, 2?: Restriction, 3?: int|null}> $table
     *
     * @return array<string, InventoryNight>
     */
    public static function nights(array $table): array
    {
        $out = [];
        foreach ($table as $date => $n) {
            $out[$date] = new InventoryNight(Days::date($date), $n[0], $n[1] ?? 1, $n[3] ?? null, $n[2] ?? Restriction::None);
        }

        return $out;
    }
}
