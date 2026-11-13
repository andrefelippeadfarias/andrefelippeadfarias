<?php

declare(strict_types=1);

namespace Ritmo\Application\Verify;

use DateTimeImmutable;
use Ritmo\Application\Approval\ApprovalRefused;
use Ritmo\Application\Approval\CapabilityCheck;
use Ritmo\Domain\Model\Property;
use Ritmo\Domain\Orders\OwnerOrder;
use Ritmo\Domain\Support\Clock;
use Ritmo\Domain\Write\State\NightFields;
use Ritmo\Domain\Write\ThirdParty\DetectedFreeze;
use Ritmo\Domain\Write\ThirdParty\ManualChangeDetector;
use Ritmo\Domain\Write\ThirdParty\Observation;
use Ritmo\Domain\Write\ThirdParty\ThirdPartyConfig;
use Ritmo\Infrastructure\Db\Repository\DetectedFreezeRepository;
use Ritmo\Infrastructure\Db\Repository\NightObservationRepository;

/**
 * Before the engine runs: compares this run's reads with the last observation of each night,
 * freezes the nights someone else changed (both systems, exactly those nights) and keeps the
 * reads as the new observations. The run adds orders() to its order book, so the engine stays
 * away from the frozen nights and the Validator refuses any item or deletion there; nothing
 * writes over them while frozen (task rule 9(l)). Only a person releases a freeze early.
 */
final class ThirdPartyWatch
{
    public function __construct(
        private readonly NightObservationRepository $observations,
        private readonly DetectedFreezeRepository $freezes,
        private readonly CapabilityCheck $capabilities,
        private readonly Clock $clock,
        private readonly ThirdPartyConfig $config = new ThirdPartyConfig(),
        private readonly ManualChangeDetector $detector = new ManualChangeDetector(),
    ) {
    }

    /**
     * @param list<Observation>          $current    this run's reads, before any write
     * @param array<string, bool>        $unresolved nights with a write of the plugin whose outcome is unknown
     * @param array<string, NightFields> $pushed     PMS nights => the values the pricing system's push sets there
     */
    public function inspect(Property $property, array $current, array $unresolved = [], array $pushed = [], ?int $runId = null): ThirdPartyResult
    {
        if ($current === []) {
            return new ThirdPartyResult([], [], $this->orders($property));
        }
        $nights = array_map(static fn (Observation $o): DateTimeImmutable => $o->night, $current);
        $previous = $this->observations->latest($property->id, min($nights), max($nights));
        $changes = $this->detector->detect($previous, $current, $unresolved, $pushed, $this->clock->today($property->timezone));
        $opened = array_map(
            fn (DetectedFreeze $freeze): DetectedFreeze => $freeze->withId($this->freezes->add($property->id, $freeze, $runId)),
            DetectedFreeze::fromChanges($changes, $this->clock->now(), $this->config),
        );
        $this->observations->recordChanged($property->id, $current, $runId);

        return new ThirdPartyResult($changes, $opened, $this->orders($property));
    }

    /**
     * The manual orders of the property's active freezes.
     *
     * @return list<OwnerOrder>
     */
    public function orders(Property $property): array
    {
        $now = $this->clock->now();
        $today = $this->clock->today($property->timezone);
        $orders = [];
        foreach ($this->freezes->unreleased($property->id) as $freeze) {
            array_push($orders, ...$freeze->toOrders($now, $today));
        }

        return $orders;
    }

    /**
     * A person releases a freeze.
     *
     * @throws ApprovalRefused when the user lacks the approval capability
     */
    public function release(int $freezeId, int $userId): bool
    {
        if (!$this->capabilities->userCan($userId, CapabilityCheck::APPROVE)) {
            throw new ApprovalRefused(ApprovalRefused::NO_CAPABILITY, 'Releasing a frozen night needs the approval capability.');
        }

        return $this->freezes->release($freezeId, $userId, $this->clock->now());
    }
}
