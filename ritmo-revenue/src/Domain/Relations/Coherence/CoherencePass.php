<?php

declare(strict_types=1);

namespace Ritmo\Domain\Relations\Coherence;

use DateTimeImmutable;
use Ritmo\Domain\Relations\Params\SwitchParams;
use Ritmo\Domain\Relations\Relation;
use Ritmo\Domain\Relations\RelationStage;
use Ritmo\Domain\Relations\RelationType;
use Ritmo\Domain\Relations\ScanMode;
use Ritmo\Domain\Relations\StrategyNights;

/**
 * The relations after the engine, night by night, on the plan draft.
 *
 * On each night every relation of stage "coherence" runs in resolution order, unless its scope
 * leaves the night out, its required owner order is not active, it is of scan "touched" and the
 * engine decided no price for a room of its group at the start of the pass, or the ladder nights
 * setting skips it. A switch that holds the night suspends the relations it lists.
 */
final class CoherencePass
{
    /** @var array<string, CoherenceRule> */
    private array $rules;

    public function __construct(private readonly CoherenceContext $context)
    {
        $adjuster = new TargetAdjuster($context);
        $this->rules = [
            RelationType::AtLeast->value => new AtLeastRule($context, $adjuster),
            RelationType::Between->value => new BetweenRule($context, $adjuster),
            RelationType::PremiumOverMax->value => new PremiumRule($context, $adjuster),
            RelationType::DiscountBand->value => new DiscountBandRule($context),
            RelationType::OccupancySwitch->value => new OccupancySwitchRule($context),
        ];
    }

    /**
     * Runs the pass on the given nights (see {@see NightSet::of()}) and returns the report, also
     * kept in the context. The draft and the decided prices of the facts change in place.
     *
     * @param list<DateTimeImmutable> $nights
     */
    public function run(array $nights): RelationReport
    {
        $touched = array_fill_keys(array_keys($this->context->facts->decidedPrices()), true);
        usort($nights, static fn (DateTimeImmutable $a, DateTimeImmutable $b): int => $a <=> $b);
        $relations = $this->context->graph->orderedFor(RelationStage::Coherence);
        foreach ($nights as $night) {
            $suspended = [];
            foreach ($relations as $relation) {
                if (isset($suspended[$relation->id]) || !$this->runsTonight($relation, $night, $touched)) {
                    continue;
                }
                $outcome = $this->outcome($relation, $night);
                $this->context->report->record(new RelationDecision($relation->id, $relation->type, $relation->target, $night, $outcome));
                if ($outcome->isSwitchHolding() && $relation->params instanceof SwitchParams) {
                    $suspended += array_fill_keys($relation->params->suspends, true);
                }
            }
        }

        return $this->context->report;
    }

    private function outcome(Relation $relation, DateTimeImmutable $night): Outcome
    {
        if ($this->context->facts->row($relation->target, $night) === null) {
            return Outcome::NoData;
        }
        $order = $relation->scope->requiresOrder;
        if ($order !== null && !$this->context->hooks->orders->isActive($order, $relation->target, $night)) {
            return Outcome::NotAuthorised;
        }

        return $this->rules[$relation->type->value]->evaluate($relation, $night);
    }

    /**
     * @param array<string, true> $touched room-night keys decided before the pass
     */
    private function runsTonight(Relation $relation, DateTimeImmutable $night, array $touched): bool
    {
        if (!$relation->scope->matches($night, $this->context->nightType($night), $this->context->today)) {
            return false;
        }
        $group = $this->context->graph->groupOf($relation->target);
        if ($relation->scan === ScanMode::Touched && !self::anyIn($group, $night, $touched)) {
            return false;
        }
        $governed = false;
        foreach ($group as $room) {
            $governed = $governed || $this->context->facts->isStrategyDecided($room, $night);
        }

        return match ($relation->strategyNights) {
            StrategyNights::Apply => true,
            StrategyNights::Skip => !$governed,
            StrategyNights::Only => $governed,
        };
    }

    /**
     * @param list<string>        $rooms
     * @param array<string, true> $keys
     */
    private static function anyIn(array $rooms, DateTimeImmutable $night, array $keys): bool
    {
        foreach ($rooms as $room) {
            if (isset($keys[RoomNight::key($room, $night)])) {
                return true;
            }
        }

        return false;
    }
}
