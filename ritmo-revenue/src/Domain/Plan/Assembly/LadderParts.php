<?php

declare(strict_types=1);

namespace Ritmo\Domain\Plan\Assembly;

use DateTimeImmutable;
use Ritmo\Domain\Calendar\HolidayCalendar;
use Ritmo\Domain\Config\RuleSet;
use Ritmo\Domain\Market\PriceFactor;
use Ritmo\Domain\Model\DateOverride;
use Ritmo\Domain\Model\PriceNight;
use Ritmo\Domain\Model\RoomType;
use Ritmo\Domain\Orders\OrderBook;
use Ritmo\Domain\Pricing\Cadence\StepCadence;
use Ritmo\Domain\Pricing\Cadence\StepLedger;
use Ritmo\Domain\Pricing\Floors;
use Ritmo\Domain\Pricing\Ladder\LadderHardFloor;
use Ritmo\Domain\Pricing\Ladder\LadderItemBuilder;
use Ritmo\Domain\Pricing\Ladder\LadderNight;
use Ritmo\Domain\Pricing\Ladder\LadderNightEvaluator;
use Ritmo\Domain\Pricing\Ladder\LadderOutcome;
use Ritmo\Domain\Pricing\Ladder\LadderReferences;
use Ritmo\Domain\Pricing\Ladder\LadderRelations;
use Ritmo\Domain\Pricing\Ladder\LadderRoomQuote;
use Ritmo\Domain\Pricing\Ladder\LadderStrategy;
use Ritmo\Domain\Pricing\Ladder\LastMinuteMarketFloor;
use Ritmo\Domain\Pricing\Ladder\MarketRuler;
use Ritmo\Domain\Pricing\Ladder\SalesLadder;
use Ritmo\Domain\Pricing\Ladder\WeekendFloorOverride;

/**
 * The sales ladder strategy of a run, wired once: the night evaluator, the relations solved inside
 * a ladder night, the item builder and the two floors the strategy changes elsewhere (the
 * last-minute market floor of the pace step, the weekend owner floor of the standard floors).
 *
 * Reference: plano_da_noite, relativos_recanto and item_do_plano as pace._plano calls them.
 */
final class LadderParts
{
    /** @var array<string, list<string>> room => the rooms its ladder price is compared with */
    private array $references = [];

    private function __construct(
        public readonly LadderStrategy $strategy,
        private readonly LadderNightEvaluator $evaluator,
        private readonly LadderRelations $relations,
        private readonly LadderItemBuilder $builder,
        public readonly LastMinuteMarketFloor $marketFloor,
        public readonly WeekendFloorOverride $weekendOverride,
        public readonly SalesLadder $ladder,
    ) {
        foreach ($strategy->between as $rule) {
            $this->references[$rule->room] = array_values(array_unique([...($this->references[$rule->room] ?? []), $rule->low, $rule->high]));
        }
        foreach ($strategy->premium as $rule) {
            $this->references[$rule->room] = array_values(array_unique([...($this->references[$rule->room] ?? []), ...$rule->over]));
        }
    }

    /**
     * The parts of the first active strategy of the rule set, or null when no strategy acts (the
     * ladder off, or no ruler of the market to price it).
     *
     * @param array<string, RoomType> $rooms by code
     */
    public static function fromRules(RuleSet $rules, array $rooms, ?MarketRuler $ruler, StepCadence $cadence): ?self
    {
        $strategy = $rules->ladder()->activeStrategies()[0] ?? null;
        if ($strategy === null || $ruler === null || $strategy->mode !== LadderStrategy::MODE_STEPPED) {
            return null;
        }
        $calendar = new HolidayCalendar($rules);
        $factors = new PriceFactor($rules);
        $ladder = new SalesLadder($strategy, $rooms, $rules->qualityFloor(), $factors, $calendar);
        $hardFloors = new LadderHardFloor(new Floors($rules, $factors), $calendar, $factors, $rules->holidayFloor());
        $relations = new LadderRelations($ladder, $calendar, $rules->weekendDays());

        return new self(
            $strategy,
            new LadderNightEvaluator($ladder, $ruler, $hardFloors, $relations),
            $relations,
            new LadderItemBuilder($strategy, $cadence, $rules->cadence(), $rules->tolerance()),
            new LastMinuteMarketFloor($ladder, $ruler, $hardFloors, $calendar),
            new WeekendFloorOverride($strategy, $rules->qualityFloor(), $factors, $calendar),
            $ladder,
        );
    }

    /**
     * @param array<string, PriceNight> $rows    the calendar nights of the date, by room
     * @param array<string, true>       $blocked rooms closed in the PMS that night
     */
    public function evaluate(DateTimeImmutable $night, int $daysOut, array $rows, OrderBook $orders, array $blocked): LadderNight
    {
        return $this->evaluator->evaluate($night, $daysOut, $rows, $orders, $blocked);
    }

    /**
     * The strategy governs the room night (reference: plano_dono.governa_noite).
     *
     * @param array<string, true> $blocked rooms closed in the PMS that night
     */
    public function acts(string $room, DateTimeImmutable $night, OrderBook $orders, array $blocked): bool
    {
        return $this->evaluator->acts($room, $night, $orders, $blocked);
    }

    /**
     * The room's ladder price depends on other ladder rooms (a between or premium rule).
     */
    public function isRelational(string $room): bool
    {
        return isset($this->references[$room]);
    }

    /**
     * The night again with the relations solved against what the reference rooms really keep.
     */
    public function reapply(LadderNight $night, LadderReferences $references): LadderNight
    {
        return $this->relations->apply($night, $references);
    }

    /**
     * A light run reaches a ladder room night when the night is in the filter, or, for a room
     * priced against others, when one of those rooms is (its price follows theirs).
     */
    public function inFilter(RunInputs $inputs, string $room, DateTimeImmutable $night): bool
    {
        if ($inputs->inFilter($room, $night)) {
            return true;
        }
        foreach ($this->references[$room] ?? [] as $reference) {
            if ($inputs->inFilter($reference, $night)) {
                return true;
            }
        }

        return false;
    }

    public function build(
        RoomType $room,
        LadderRoomQuote $quote,
        int $daysOut,
        PriceNight $live,
        ?DateOverride $override,
        StepLedger $ledger,
        DateTimeImmutable $now,
        bool $inFilter,
        float $listingTop,
    ): LadderOutcome {
        return $this->builder->build($room, $quote, $daysOut, $live, $override, $ledger, $now, $inFilter, $listingTop);
    }
}
