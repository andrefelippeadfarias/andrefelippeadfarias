<?php

declare(strict_types=1);

namespace Ritmo\Domain\Plan;

use Ritmo\Domain\Calendar\Stay\StayRules;
use Ritmo\Domain\Orders\OrderBook;
use Ritmo\Domain\Plan\Port\NoStrategy;
use Ritmo\Domain\Plan\Port\StrategyGuard;
use Ritmo\Domain\Relations\Coherence\RelationReport;
use Ritmo\Domain\Relations\RelationGraph;
use Ritmo\Domain\Relations\Validate\DiscountBandCheck;

/**
 * What the validator reads besides the plan and the rule set: the fresh calendar, the owner
 * orders, the strategies behind them, the stay rules, the discount bands with the run's relation
 * report, the listing values at the start of the week and the relations between the rooms (the
 * order the plan must keep after refusals, Validation\RelationOrderCheck). Every part is optional: what is
 * missing turns its checks off (or, for a decision that needs the calendar, refuses it).
 */
final class ValidationContext
{
    /**
     * @param array<string, array{base?: float, min?: float}> $weeklyReference room code => listing values at the start of the week
     * @param bool $acceptStaleCalendar an explicit acceptance of a calendar older than the configured hours, for this validation
     */
    public function __construct(
        public readonly ?FreshCalendar $calendar = null,
        public readonly OrderBook $orders = new OrderBook(),
        public readonly StrategyGuard $strategies = new NoStrategy(),
        public readonly ?StayRules $stay = null,
        public readonly ?DiscountBandCheck $bands = null,
        public readonly ?RelationReport $relationReport = null,
        public readonly array $weeklyReference = [],
        public readonly bool $acceptStaleCalendar = false,
        public readonly ?RelationGraph $relations = null,
    ) {
    }
}
