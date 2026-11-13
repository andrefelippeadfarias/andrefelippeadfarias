<?php

declare(strict_types=1);

namespace Ritmo\Domain\Plan\Assembly;

use Ritmo\Domain\Calendar\HolidayCalendar;
use Ritmo\Domain\Calendar\Stay\Occupancy;
use Ritmo\Domain\Calendar\Stay\PricingStayNight;
use Ritmo\Domain\Calendar\Stay\StayRules;
use Ritmo\Domain\Config\RuleSet;
use Ritmo\Domain\Market\PriceFactor;
use Ritmo\Domain\Plan\Assembly\Hooks\BookOrderGuard;
use Ritmo\Domain\Plan\Assembly\Hooks\LadderStrategyGuard;
use Ritmo\Domain\Plan\Assembly\Hooks\LadderStrategyView;
use Ritmo\Domain\Plan\Assembly\Hooks\RulerMarketTarget;
use Ritmo\Domain\Plan\Assembly\Hooks\RunFloorSource;
use Ritmo\Domain\Plan\CalendarNight;
use Ritmo\Domain\Plan\FreshCalendar;
use Ritmo\Domain\Plan\PlanItem;
use Ritmo\Domain\Plan\Port\NoStrategy;
use Ritmo\Domain\Plan\ValidationContext;
use Ritmo\Domain\Pricing\Cadence\StepCadence;
use Ritmo\Domain\Pricing\Floors;
use Ritmo\Domain\Pricing\PaceEngine;
use Ritmo\Domain\Pricing\Protection\SoldOutProtection;
use Ritmo\Domain\Pricing\Terms\NightTermsResolver;
use Ritmo\Domain\Relations\Coherence\NightFacts;
use Ritmo\Domain\Relations\Coherence\RelationReport;
use Ritmo\Domain\Relations\Config\RelationsConfig;
use Ritmo\Domain\Relations\ListingCapChain;
use Ritmo\Domain\Relations\Neutral\ConfiguredPriceBands;
use Ritmo\Domain\Relations\Neutral\HardFloorSource;
use Ritmo\Domain\Relations\Neutral\NoMarketTarget;
use Ritmo\Domain\Relations\Neutral\RuleSetNightCalendar;
use Ritmo\Domain\Relations\Port\RelationHooks;
use Ritmo\Domain\Relations\Relation;
use Ritmo\Domain\Relations\RelationGraph;
use Ritmo\Domain\Relations\RelationType;
use Ritmo\Domain\Relations\Validate\DiscountBandCheck;
use Ritmo\Domain\Support\Clock;

/**
 * The collaborators of one assembly, wired from the rule set and the inputs of the run: the
 * relations of the property, the sales ladder, the terms of the pace step, the hooks the relations
 * and the validator ask, and the stay rules.
 */
final class RunWiring
{
    public readonly RunCalendar $calendar;

    public readonly RelationsConfig $relationsConfig;

    public readonly RelationGraph $graph;

    public readonly ListingCapChain $caps;

    public readonly ?LadderParts $ladder;

    public readonly NightTermsResolver $terms;

    public readonly RelationHooks $hooks;

    public readonly StayRules $stay;

    public function __construct(private readonly RuleSet $rules, private readonly Clock $clock, public readonly RunInputs $inputs)
    {
        $this->calendar = new RunCalendar($inputs, $rules);
        $this->relationsConfig = $rules->relations();
        $relations = array_values(array_filter($this->relationsConfig->relations, static fn (Relation $r): bool => $r->propertyId === $inputs->property->id));
        $this->graph = new RelationGraph($relations, array_values($inputs->rooms));
        $this->caps = new ListingCapChain($this->graph, $this->relationsConfig->settings);
        $this->ladder = LadderParts::fromRules($rules, $inputs->rooms, $inputs->ruler, $this->cadence());
        $this->terms = new NightTermsResolver($rules, $rules->orders(), new Floors($rules), $inputs->orders, $this->ladder?->marketFloor, $this->ladder?->weekendOverride, $inputs->property->timezone);
        $this->hooks = $this->hooks();
        $this->stay = new StayRules($rules->stay(), new HolidayCalendar($rules), array_values($inputs->rooms));
    }

    public function cadence(): StepCadence
    {
        return new StepCadence($this->rules->cadence());
    }

    /**
     * What the run knows of every room night before the coherence stage: the calendar, the
     * nights closed in the PMS and the light-run filter; the prices decided are added as the
     * nights are priced.
     */
    public function pricingFacts(): NightFacts
    {
        return $this->facts([], []);
    }

    /**
     * @param array<string, float> $decided         room-night key => effective price of an item of the run
     * @param array<string, float> $strategyDecided room-night key => what stays on the channel on a ladder night
     */
    public function facts(array $decided, array $strategyDecided): NightFacts
    {
        $filter = $this->inputs->lightFilter === null ? null : array_keys($this->inputs->lightFilter);

        return new NightFacts(array_values($this->inputs->rooms), $this->calendar->lists(), $decided, $strategyDecided, $filter, array_keys($this->inputs->blocked));
    }

    public function nightPricing(NightFacts $facts): NightPricing
    {
        return new NightPricing(
            $this->rules,
            $this->calendar,
            $facts,
            new PaceEngine($this->rules, $this->clock),
            $this->terms,
            new SoldOutProtection($this->rules->soldOut(), $this->rules->tolerance(), $this->rules->noEffect()->moneySlack),
            $this->cadence(),
            $this->graph,
            $this->caps,
            $this->hooks->bands,
            $this->hooks->calendar,
            $this->ladder,
        );
    }

    /**
     * What the validator needs besides the plan: the calendar the run read, the owner orders, the
     * strategy behind its order, the stay rules and the discount bands with the run's relations report.
     *
     * With $relationOrder the validator also checks the order of the related rooms in currency
     * (RelationOrderCheck): only for a plan cut down after refusals, an approval or a hold, whose
     * remaining items the relations stage did not see together. The plan as the relations stage
     * built it is coherent by construction and is not checked again (the check is stricter than
     * the reference there; docs/VALIDATOR.md).
     */
    public function validationContext(RelationReport $report, bool $relationOrder = false): ValidationContext
    {
        $strategy = $this->ladder === null ? new NoStrategy() : new LadderStrategyGuard(
            $this->ladder,
            $this->inputs->orders,
            $this->inputs->today,
            array_values(array_filter($this->graph->ordered(), static fn (Relation $r): bool => $r->type === RelationType::OccupancySwitch)),
            $report,
            $this->inputs->blocked,
        );

        return new ValidationContext(
            $this->freshCalendar(),
            $this->inputs->orders,
            $strategy,
            $this->stay,
            new DiscountBandCheck($this->graph, $this->relationsConfig->settings, $this->hooks->calendar, $this->hooks->orders),
            $report,
            $this->inputs->weeklyReference,
            relations: $relationOrder ? $this->graph : null,
        );
    }

    /**
     * Free and sold-out nights of every room, as the calendar of the run says, for the stay rules.
     *
     * @return array<string, Occupancy>
     */
    public function occupancy(): array
    {
        $out = [];
        foreach ($this->inputs->rooms as $code => $room) {
            $nights = [];
            foreach ($this->inputs->calendar[$code] ?? [] as $key => $night) {
                $nights[(string) $key] = PricingStayNight::fromPriceNight($night);
            }
            $out[$code] = Occupancy::fromPricing($nights, $room->units);
        }

        return $out;
    }

    public function freshCalendar(): FreshCalendar
    {
        $nights = [];
        foreach ($this->inputs->calendar as $code => $days) {
            foreach ($days as $night) {
                $nights[PlanItem::keyOf((string) $code, $night->date)] = new CalendarNight($night->price, $night->minPrice, $night->sold, $night->total);
            }
        }

        return new FreshCalendar($nights, $this->inputs->readAt);
    }

    private function hooks(): RelationHooks
    {
        $calendar = new RuleSetNightCalendar($this->rules);
        $rooms = array_values($this->inputs->rooms);
        $strategy = new LadderStrategyView($this->ladder, $this->inputs->orders, $this->inputs->blocked, $this->rules->salesRampTop() !== null);

        return new RelationHooks(
            $calendar,
            new BookOrderGuard($this->inputs->orders),
            $strategy,
            new RunFloorSource($this->rules, $this->calendar, $this->terms, new HardFloorSource($this->rules, $rooms), $this->inputs->anchors, $this->ladder?->marketFloor),
            new ConfiguredPriceBands($this->relationsConfig->priceBands, $rooms, new PriceFactor($this->rules), $calendar, $strategy),
            $this->ladder === null || $this->inputs->ruler === null ? new NoMarketTarget() : new RulerMarketTarget($this->inputs->ruler, $this->ladder->ladder),
        );
    }
}
