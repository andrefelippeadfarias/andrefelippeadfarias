<?php

declare(strict_types=1);

namespace Ritmo\Tests\Unit\Domain\Calendar\Stay;

use DateTimeImmutable;
use Ritmo\Domain\Calendar\HolidayCalendar;
use Ritmo\Domain\Calendar\Stay\InventoryAudit;
use Ritmo\Domain\Calendar\Stay\Occupancy;
use Ritmo\Domain\Calendar\Stay\PricingStayNight;
use Ritmo\Domain\Calendar\Stay\StayConfig;
use Ritmo\Domain\Calendar\Stay\StayRules;
use Ritmo\Domain\Calendar\Stay\StaySyncCapability;
use Ritmo\Domain\Calendar\Stay\StaySyncField;
use Ritmo\Domain\Model\DateOverride;
use Ritmo\Domain\Model\InventoryNight;
use Ritmo\Domain\Model\Restriction;
use Ritmo\Domain\Support\Days;
use Ritmo\Tests\Support\Fixtures;

/**
 * The parity configuration of the stay rules and the frozen scenarios of the golden files, as
 * plugin objects.
 */
final class StayParity
{
    public const GOLDEN = 'golden/m2/stay/';

    /** @var array<string, array<string, mixed>> */
    private static array $scenarios = [];

    /** @var array<string, array<string, array<string, InventoryNight>>> */
    private static array $inventories = [];

    /** The pricing connector of the reference engine carries minimum stay and arrival/departure by date. */
    public static function capability(): StaySyncCapability
    {
        return new StaySyncCapability(StaySyncField::MinStay, StaySyncField::ArrivalDeparture);
    }

    public static function config(): StayConfig
    {
        return StayConfig::fromArray(Fixtures::json('presets/parity-stay.json')['stay']);
    }

    public static function rules(): StayRules
    {
        $preset = Fixtures::parityPreset();

        return new StayRules(self::config(), new HolidayCalendar($preset->rules), $preset->roomTypes);
    }

    public static function audit(): InventoryAudit
    {
        return new InventoryAudit(self::rules(), self::capability());
    }

    /**
     * @return array<string, mixed>
     */
    public static function scenario(string $name): array
    {
        return self::$scenarios[$name] ??= Fixtures::json(self::GOLDEN . "frozen/scenario-$name.json");
    }

    /**
     * @param array<string, mixed> $scenario
     *
     * @return array<string, array<string, InventoryNight>>
     */
    public static function inventory(array $scenario): array
    {
        return self::$inventories[$scenario['name']] ??= self::readInventory($scenario);
    }

    /**
     * @param array<string, mixed> $scenario
     *
     * @return array<string, array<string, InventoryNight>>
     */
    private static function readInventory(array $scenario): array
    {
        $out = [];
        foreach ($scenario['inventory'] as $room => $nights) {
            foreach ($nights as $date => $n) {
                $out[$room][$date] = new InventoryNight(
                    Days::date($date),
                    $n['numAvail'],
                    $n['minStay'],
                    $n['maxStay'],
                    Restriction::from(self::RESTRICTION[$n['override']]),
                );
            }
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $scenario
     *
     * @return array<string, PricingStayNight>
     */
    public static function calendar(array $scenario, string $room): array
    {
        $out = [];
        foreach ($scenario['pricing'][$room] as $row) {
            $out[$row['date']] = new PricingStayNight(
                Days::date($row['date']),
                (int) (float) ($row['min_stay'] ?: 1),
                $row['sold'],
                $row['total'],
                $row['check_in'] ?? null,
                $row['check_out'] ?? null,
                !in_array(strtolower((string) ($row['check_in_check_out_enabled'] ?? '1')), ['0', 'false'], true),
            );
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $scenario
     */
    public static function occupancy(array $scenario, string $room): Occupancy
    {
        $units = Fixtures::parityPreset()->roomType($room)->units;
        if (in_array($room, $scenario['plan_from_calendar'], true)) {
            return Occupancy::fromPricing(self::calendar($scenario, $room), $units);
        }

        return Occupancy::fromInventory(self::inventory($scenario)[$room], $units, self::readAt($scenario));
    }

    /**
     * @param array<string, mixed> $scenario
     *
     * @return array<string, DateOverride>|null
     */
    public static function dateRules(array $scenario, string $room): ?array
    {
        if ($scenario['date_rules'] === null) {
            return null;
        }
        $out = [];
        foreach ($scenario['date_rules'][$room] ?? [] as $date => $r) {
            $out[$date] = new DateOverride(
                Days::date($date),
                isset($r['min_price']) ? (float) $r['min_price'] : null,
                isset($r['max_price']) ? (float) $r['max_price'] : null,
                $r['min_stay'] ?? null,
                $r['check_in'] ?? null,
                $r['check_out'] ?? null,
                $r['reason'] ?? '',
                isset($r['price']) ? (float) $r['price'] : null,
                $r['price_type'] ?? null,
            );
        }

        return $out;
    }

    /**
     * Divergence SW-X5 (STAY.md): a PMS change made for an orphan night or a future orphan side
     * carries the proof of this run's PMS read (the plugin refuses it without one); the
     * reference's Beds24 batch carries none. The proof is built from the frozen inventory: the
     * night and its sold-out neighbour (the next night of an arrival-rule orphan, the night before
     * a closed day), as numbers available.
     *
     * @param array<string, mixed>      $scenario
     * @param array<string, mixed>|null $proof the reference's proof
     *
     * @return array<string, int|string>|null
     */
    public static function pmsOrphanProof(array $scenario, string $room, string $night, string $reason, ?array $proof): ?array
    {
        $neighbour = match ($reason) {
            'orphan_night' => 'next',
            'orphan_side_closed_day_sold_out', 'orphan_side_previous_sold_out' => 'previous',
            default => null,
        };
        if ($proof !== null || $neighbour === null) {
            return $proof;
        }
        $other = Days::key(Days::add(Days::date($night), $neighbour === 'next' ? 1 : -1));
        $inventory = $scenario['inventory'][$room];

        return array_filter([
            'source' => 'pms',
            'neighbour' => $neighbour,
            'units' => Fixtures::parityPreset()->roomType($room)->units,
            'night_available' => $inventory[$night]['numAvail'] ?? null,
            'neighbour_available' => $inventory[$other]['numAvail'] ?? null,
            'read_at' => self::readAt($scenario)->format(DATE_ATOM),
        ], static fn (mixed $value): bool => $value !== null);
    }

    /**
     * @param array<string, mixed> $scenario
     */
    public static function readAt(array $scenario): DateTimeImmutable
    {
        return new DateTimeImmutable($scenario['read_at']);
    }

    /**
     * @return array<string, string> room code => property id
     */
    public static function propertyOfRoom(): array
    {
        $out = [];
        foreach (Fixtures::parityPreset()->roomTypes as $room) {
            $out[$room->code] = $room->propertyId;
        }

        return $out;
    }

    /** Restrictions of the PMS as the reference engine writes them. */
    public const RESTRICTION = [
        'none' => 'none',
        'blackout' => 'blackout',
        'noCheckIn' => 'no_checkin',
        'noCheckOut' => 'no_checkout',
        'noCheckInOrCheckOut' => 'no_checkin_checkout',
    ];
}
