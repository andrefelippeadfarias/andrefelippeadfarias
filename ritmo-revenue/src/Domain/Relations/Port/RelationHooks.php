<?php

declare(strict_types=1);

namespace Ritmo\Domain\Relations\Port;

/**
 * The answers relations need from other subsystems, in one bundle.
 */
final class RelationHooks
{
    public function __construct(
        public readonly NightCalendar $calendar,
        public readonly OrderGuard $orders,
        public readonly StrategyView $strategy,
        public readonly FloorSource $floors,
        public readonly PriceBandSource $bands,
        public readonly MarketTargetSource $market,
    ) {
    }
}
