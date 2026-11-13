<?php

declare(strict_types=1);

namespace Ritmo\Tests\Unit\Domain\Stay\Write;

use DateTimeImmutable;
use Ritmo\Domain\Calendar\Stay\PricingStayPlan;
use Ritmo\Domain\Calendar\Stay\StayBatch;
use Ritmo\Domain\Stay\Write\PushedPmsValues;
use Ritmo\Domain\Stay\Write\StayPairing;
use Ritmo\Domain\Stay\Write\StayWriteConfig;
use Ritmo\Domain\Stay\Write\StayWriteInputs;
use Ritmo\Domain\Stay\Write\StayWritePlanner;
use Ritmo\Domain\Support\Days;
use Ritmo\Tests\Support\Fixtures;
use Ritmo\Tests\Unit\Domain\Calendar\Stay\StayParity;

/**
 * The stay-write planner over the frozen M2 stay scenarios (golden/m2/stay), with the parity
 * configuration of the stay rules.
 */
final class StayWriteParity
{
    private function __construct()
    {
    }

    public static function planner(?StayWriteConfig $config = null): StayWritePlanner
    {
        $stay = StayParity::config();

        return new StayWritePlanner(
            $config ?? new StayWriteConfig(true, 366, 2000, 2, false),
            StayParity::audit(),
            new StayBatch($stay->batch, StayParity::propertyOfRoom()),
            new PricingStayPlan(StayParity::rules()),
            new StayPairing(self::pushed()),
        );
    }

    public static function pushed(): PushedPmsValues
    {
        return new PushedPmsValues(StayParity::config()->pricingSync, StayParity::capability());
    }

    /**
     * @param array<string, mixed> $scenario
     * @param array<string, true>  $frozen
     */
    public static function inputs(array $scenario, ?DateTimeImmutable $lastPricingNight = null, array $frozen = [], array $priceItems = []): StayWriteInputs
    {
        $calendar = $occupancy = $rules = [];
        foreach (Fixtures::parityPreset()->roomTypes as $room) {
            $calendar[$room->code] = StayParity::calendar($scenario, $room->code);
            $occupancy[$room->code] = StayParity::occupancy($scenario, $room->code);
            $rules[$room->code] = StayParity::dateRules($scenario, $room->code);
        }

        return new StayWriteInputs(
            Days::date($scenario['today']),
            Days::date($scenario['audit_from']),
            Days::date($scenario['last']),
            StayParity::readAt($scenario),
            StayParity::inventory($scenario),
            $calendar,
            $occupancy,
            $scenario['effective'] ?? [],
            $rules,
            $priceItems,
            $frozen,
            $lastPricingNight ?? Days::date($scenario['last']),
        );
    }
}
