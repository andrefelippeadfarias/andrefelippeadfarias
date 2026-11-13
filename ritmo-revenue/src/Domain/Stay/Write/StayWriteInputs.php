<?php

declare(strict_types=1);

namespace Ritmo\Domain\Stay\Write;

use DateTimeImmutable;
use Ritmo\Domain\Calendar\Stay\Occupancy;
use Ritmo\Domain\Calendar\Stay\PricingStayNight;
use Ritmo\Domain\Model\DateOverride;
use Ritmo\Domain\Model\InventoryNight;
use Ritmo\Domain\Plan\PlanItem;

/**
 * What one run read and decided that the stay writes plan from: the nights to look at (a full
 * run: today .. today + stay.write.horizon_days; a light run: the orphan window), the PMS
 * inventory and the pricing calendar of this run, the stays and date rules in force, the price
 * items of the plan, the frozen nights and the last night the pricing system may be written.
 */
final class StayWriteInputs
{
    /**
     * @param array<string, array<string, InventoryNight>>   $inventory  room => night => PMS night
     * @param array<string, array<string, PricingStayNight>> $calendar   room => night => pricing night
     * @param array<string, Occupancy>                       $occupancy  room => occupancy the pricing stay plan reads
     * @param array<string, array<string, int>|null>         $effective  room => night => stay in force (null: not read)
     * @param array<string, array<string, DateOverride>|null> $dateRules room => night => date rule in force (null: not read)
     * @param list<PlanItem>                                 $priceItems
     * @param array<string, true>                            $frozen     "room|night"
     */
    public function __construct(
        public readonly DateTimeImmutable $today,
        public readonly DateTimeImmutable $from,
        public readonly DateTimeImmutable $to,
        public readonly ?DateTimeImmutable $readAt,
        public readonly array $inventory,
        public readonly array $calendar,
        public readonly array $occupancy,
        public readonly array $effective,
        public readonly array $dateRules,
        public readonly array $priceItems,
        public readonly array $frozen,
        public readonly DateTimeImmutable $lastPricingNight,
    ) {
    }
}
