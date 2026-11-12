<?php

declare(strict_types=1);

namespace Ritmo\Domain\Plan\Assembly\Hooks;

use DateTimeImmutable;
use Ritmo\Domain\Orders\OrderBook;
use Ritmo\Domain\Plan\Assembly\LadderParts;
use Ritmo\Domain\Plan\PlanItem;
use Ritmo\Domain\Relations\Port\StrategyView;

/**
 * What the sales ladder of the run says to the relations: which room nights it governs, and
 * which a target order with a sales ramp pins (reference: PD.governa_noite and the "ladder pin"
 * of pace.coerencia).
 */
final class LadderStrategyView implements StrategyView
{
    /**
     * @param array<string, true> $blocked room nights closed in the PMS (PlanItem::keyOf)
     */
    public function __construct(
        private readonly ?LadderParts $ladder,
        private readonly OrderBook $orders,
        private readonly array $blocked,
        private readonly bool $salesRamp,
    ) {
    }

    public function pinsFloorToCeiling(string $room, DateTimeImmutable $night): bool
    {
        $target = $this->orders->targetFor($room, $night);

        return $this->salesRamp && $target !== null && $target->terms->salesRamp;
    }

    public function governs(string $room, DateTimeImmutable $night): bool
    {
        if ($this->ladder === null) {
            return false;
        }
        $blocked = isset($this->blocked[PlanItem::keyOf($room, $night)]) ? [$room => true] : [];

        return $this->ladder->acts($room, $night, $this->orders, $blocked);
    }
}
