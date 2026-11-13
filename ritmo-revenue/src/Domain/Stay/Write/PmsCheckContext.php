<?php

declare(strict_types=1);

namespace Ritmo\Domain\Stay\Write;

use DateTimeImmutable;
use Ritmo\Domain\Model\InventoryNight;
use Ritmo\Domain\Model\RoomType;

/**
 * What the PMS change check needs from the run: the property's today by the clock read now, the
 * rooms, the inventory read in this run, the frozen nights, what the stay rules ask for, the
 * pricing items the validator accepted, the owner orders that allow availability or channel
 * limits, the revert ledger and the limits.
 */
final class PmsCheckContext
{
    /**
     * @param array<string, RoomType>                      $rooms           room code => room
     * @param array<string, array<string, InventoryNight>> $inventory       room code => night => night read in this run
     * @param array<string, true>                          $frozen          "room|night" => frozen by a third-party change or a manual freeze
     * @param array<string, true>                          $ruleTargets     PmsChangeEntry::identity() of what the stay rules ask for
     * @param array<string, true>                          $acceptedPricing plan item keys the validator accepted
     * @param array<string, true>                          $allowingOrders  owner order ids that allow availability and channel limits
     */
    public function __construct(
        public readonly DateTimeImmutable $today,
        public readonly DateTimeImmutable $now,
        public readonly array $rooms,
        public readonly array $inventory,
        public readonly array $frozen,
        public readonly array $ruleTargets,
        public readonly array $acceptedPricing,
        public readonly array $allowingOrders,
        public readonly RevertLedger $reverts,
        public readonly int $maxMinStay,
        public readonly int $proofMaxAgeHours,
        public readonly StayWriteConfig $config,
    ) {
    }
}
