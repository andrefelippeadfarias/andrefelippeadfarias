<?php

declare(strict_types=1);

namespace Ritmo\Application\Run;

use Ritmo\Domain\Calendar\Stay\PricingStayNight;
use Ritmo\Domain\Calendar\Stay\PricingSync;
use Ritmo\Domain\Calendar\Stay\StaySyncCapability;
use Ritmo\Domain\Model\DateOverride;
use Ritmo\Domain\Model\PriceNight;
use Ritmo\Domain\Stay\Write\PushedPmsValues;
use Ritmo\Domain\Support\Days;
use Ritmo\Domain\Write\State\ChangeField;
use Ritmo\Domain\Write\State\NightFields;
use Ritmo\Domain\Write\State\Provider;
use Ritmo\Domain\Write\State\WrittenNight;

/**
 * What the pricing system's push sets in the PMS (stay spec D6), for the third-party watch: for
 * the rooms whose push carries them (stay.pricing_sync), the effective minimum stay and the
 * arrival and departure of the masks in force (Stay\Write\PushedPmsValues); for every room, the
 * minimum stay of its date rules (as in M3). A PMS night that took them was not changed by
 * someone else.
 */
final class PushedFields
{
    private function __construct()
    {
    }

    /**
     * @param array<string, array<string, PriceNight>>   $pricing   room => night => pricing calendar read in this run
     * @param array<string, array<string, DateOverride>> $overrides room => night => date rule in force
     *
     * @return array<string, NightFields> WrittenNight::keyOf(Pms, room, night) => fields the push sets
     */
    public static function of(PricingSync $sync, StaySyncCapability $capability, array $pricing, array $overrides): array
    {
        $values = new PushedPmsValues($sync, $capability);
        $out = [];
        foreach ($overrides as $room => $nights) {
            foreach ($nights as $night => $override) {
                if ($override->minStay !== null) {
                    $out[WrittenNight::keyOf(Provider::Pms, (string) $room, Days::date((string) $night))] = [ChangeField::PMS_MIN_STAY => $override->minStay];
                }
            }
        }
        foreach ($pricing as $room => $nights) {
            $calendar = [];
            foreach ($nights as $key => $night) {
                $calendar[(string) $key] = PricingStayNight::fromPriceNight($night);
            }
            foreach ($values->calendar((string) $room, $calendar, $overrides[$room] ?? null) as $key => $fields) {
                $set = array_filter($fields->all(), static fn (mixed $v): bool => $v !== null);
                if ($set !== []) {
                    $at = WrittenNight::keyOf(Provider::Pms, (string) $room, Days::date((string) $key));
                    $out[$at] = $set + ($out[$at] ?? []);
                }
            }
        }

        return array_map(static fn (array $fields): NightFields => new NightFields($fields), $out);
    }
}
