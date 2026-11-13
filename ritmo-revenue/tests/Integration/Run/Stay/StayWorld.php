<?php
declare(strict_types=1);
namespace Ritmo\Tests\Integration\Run\Stay;

use Ritmo\Domain\Model\PropertyMode;
use Ritmo\Tests\Integration\Run\Support\RunWorld;

/** Helpers over RunWorld for the stay-write runs against the simulator. */
final class StayWorld
{
    public const CLOSED_SATURDAY = ['weekday' => 5, 'restriction' => 'no_checkin_checkout', 'rooms' => [], 'except_rooms' => [], 'min_stay' => null, 'open_dates' => [], 'orphan_side' => ['enabled' => false, 'on_holidays' => false, 'when_closed_day_sold_out' => 'no_checkin', 'when_previous_sold_out' => 'no_checkout']];

    /** @param array<string, mixed> $stay */
    public static function auto(array $stay, PropertyMode $mode = PropertyMode::Auto): RunWorld
    {
        $rules = ['stay' => $stay + ['write' => ['enabled' => true, 'horizon_days' => 14]]];
        if ($mode === PropertyMode::Auto) {
            $rules['modes'] = ['autonomous_enabled' => true];
        }

        return new RunWorld($mode, $rules);
    }

    /** A person changes the PMS calendar directly (not through the plugin). */
    public static function thirdParty(RunWorld $world, int $roomId, string $night, array $fields): void
    {
        $world->apis->request('POST', 'https://api.beds24.com/v2/inventory/rooms/calendar', ['token' => 'sim-long-life-token-0001', 'content-type' => 'application/json'], json_encode([['roomId' => $roomId, 'calendar' => [['from' => $night, 'to' => $night] + $fields]]]));
    }

    /** @return list<array<string, mixed>> the PMS write bodies */
    public static function pmsBodies(RunWorld $world): array
    {
        return array_values(array_filter($world->apis->writeBodies(), static fn (array $w): bool => str_contains((string) $w['path'], '/inventory/')));
    }

    public static function pmsNight(RunWorld $world, string $roomId, string $night): array
    {
        return $world->apis->world()['pms'][$roomId][$night] ?? [];
    }
}
