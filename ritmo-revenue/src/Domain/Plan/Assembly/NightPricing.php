<?php

declare(strict_types=1);

namespace Ritmo\Domain\Plan\Assembly;

use DateTimeImmutable;
use Ritmo\Domain\Calendar\HolidayCalendar;
use Ritmo\Domain\Config\RuleSet;
use Ritmo\Domain\Market\PriceFactor;
use Ritmo\Domain\Model\PricePlan;
use Ritmo\Domain\Model\RoomType;
use Ritmo\Domain\Orders\OrderGate;
use Ritmo\Domain\Plan\NightState;
use Ritmo\Domain\Pricing\Cadence\SoldBase;
use Ritmo\Domain\Pricing\Cadence\StepCadence;
use Ritmo\Domain\Pricing\Cadence\StepProposal;
use Ritmo\Domain\Pricing\Ladder\LadderNight;
use Ritmo\Domain\Pricing\Ladder\LadderReferences;
use Ritmo\Domain\Pricing\NightContext;
use Ritmo\Domain\Pricing\PaceDecision;
use Ritmo\Domain\Pricing\PaceEngine;
use Ritmo\Domain\Pricing\Protection\SoldOutProtection;
use Ritmo\Domain\Pricing\StepDirection;
use Ritmo\Domain\Pricing\Terms\NightTermsResolver;
use Ritmo\Domain\Pricing\Terms\PremiumCap;
use Ritmo\Domain\Relations\Coherence\NightFacts;
use Ritmo\Domain\Relations\ListingCapChain;
use Ritmo\Domain\Relations\Params\PremiumParams;
use Ritmo\Domain\Relations\Port\PriceBandSource;
use Ritmo\Domain\Relations\PremiumBounds;
use Ritmo\Domain\Relations\RelationGraph;
use Ritmo\Domain\Relations\RelationType;
use Ritmo\Domain\Relations\Port\NightCalendar;
use Ritmo\Domain\Support\Days;
use Ritmo\Domain\Support\Num;

/**
 * Prices every room night of the run, night after night: the gates (blocked in the PMS, frozen
 * by a manual change, fixed by the owner), the sales ladder, the protection of sold-out nights
 * and the pace step with its terms. Rooms are taken in the order of their relations (a room
 * after the rooms it is compared with), so a premium room sees what the others will be.
 *
 * Reference: pace._plano up to the coherence stage.
 */
final class NightPricing
{
    /** @var list<WorkItem> */
    private array $items = [];

    /** @var list<NightLine> */
    private array $lines = [];

    /** @var array<string, float> room-night key => what stays on the channel on a ladder night */
    private array $strategyDecided = [];

    private readonly HolidayCalendar $holidays;

    private readonly PriceFactor $factors;

    public function __construct(
        private readonly RuleSet $rules,
        private readonly RunCalendar $calendar,
        private readonly NightFacts $facts,
        private readonly PaceEngine $engine,
        private readonly NightTermsResolver $terms,
        private readonly SoldOutProtection $protection,
        private readonly StepCadence $cadence,
        private readonly RelationGraph $graph,
        private readonly ListingCapChain $caps,
        private readonly PriceBandSource $bands,
        private readonly NightCalendar $nightTypes,
        private readonly ?LadderParts $ladder,
    ) {
        $this->holidays = new HolidayCalendar($rules);
        $this->factors = new PriceFactor($rules);
    }

    public function run(): void
    {
        $inputs = $this->calendar->inputs();
        $order = $this->roomOrder();
        foreach ($inputs->nights() as $k => $night) {
            $ladderNight = $this->ladder?->evaluate($night, $k, $this->calendar->night($night), $inputs->orders, $this->blockedOn($night));
            foreach ($order as $room) {
                $row = $this->calendar->row($room->code, $night);
                if ($row !== null) {
                    $ladderNight = $this->priceNight($room, $night, $k, $ladderNight);
                }
            }
        }
    }

    /**
     * @return list<WorkItem>
     */
    public function items(): array
    {
        return $this->items;
    }

    /**
     * @return list<NightLine>
     */
    public function lines(): array
    {
        return $this->lines;
    }

    /**
     * @return array<string, float>
     */
    public function strategyDecided(): array
    {
        return $this->strategyDecided;
    }

    private function priceNight(RoomType $room, DateTimeImmutable $night, int $k, ?LadderNight $ladderNight): ?LadderNight
    {
        $inputs = $this->calendar->inputs();
        $row = $this->calendar->row($room->code, $night);
        if ($inputs->isBlocked($room->code, $night)) {
            $this->line($room, $night, NightLine::BLOCKED, []);

            return $ladderNight;
        }
        $gate = $inputs->orders->gateFor($room->code, $night);
        if ($gate !== null) {
            $this->line($room, $night, $gate->code === OrderGate::MANUAL_FREEZE ? NightLine::FROZEN : NightLine::FIXED_PRICE, ['orders' => $gate->orderIds]);

            return $ladderNight;
        }
        if ($ladderNight !== null && $ladderNight->quote($room->code) !== null) {
            return $this->ladderItem($room, $night, $k, $ladderNight);
        }
        if ($row->isSoldOut()) {
            $this->protect($room, $night, $k);

            return $ladderNight;
        }
        $this->paceStep($room, $night, $k);

        return $ladderNight;
    }

    private function ladderItem(RoomType $room, DateTimeImmutable $night, int $k, LadderNight $ladderNight): LadderNight
    {
        $inputs = $this->calendar->inputs();
        if ($this->ladder->isRelational($room->code)) {
            $ladderNight = $this->ladder->reapply($ladderNight, new LadderReferences($this->calendar->night($night), $this->blockedOn($night), $this->realsOn($night)));
        }
        $live = $this->calendar->row($room->code, $night);
        $outcome = $this->ladder->build(
            $room,
            $ladderNight->quote($room->code),
            $k,
            $live,
            $inputs->override($room->code, $night),
            $inputs->ledger,
            $inputs->now,
            $this->ladder->inFilter($inputs, $room->code, $night),
            $this->caps->capFor($room->code),
        );
        $key = $this->key($room, $night);
        $item = $outcome->item;
        $this->strategyDecided[$key] = $item === null ? $live->price : WorkItem::applied($item->minPrice, $room->base);
        if ($item === null) {
            $this->line($room, $night, NightLine::LADDER_HOLD, ['hold' => $outcome->line->hold, 'new' => $outcome->line->new, 'target_sent' => $outcome->line->targetSent]);

            return $ladderNight;
        }
        $plan = $item->toPlanItem();
        $work = new WorkItem($room->code, $night, $live->price, $item->minPrice, $item->maxPrice, WorkItem::LADDER_PRICE, $plan->reasonParams, $this->before($room, $night), $item->authorityOrderId, false, true);
        $this->add($work, NightLine::LADDER_PRICE, ['reason_codes' => $item->reasonCodes, 'target_sent' => $outcome->line->targetSent]);

        return $ladderNight;
    }

    private function protect(RoomType $room, DateTimeImmutable $night, int $k): void
    {
        $inputs = $this->calendar->inputs();
        $anchor = $inputs->anchors->forNight($night);
        if ($anchor === null) {
            $this->line($room, $night, NightLine::SOLD_OUT_NO_ANCHOR, []);

            return;
        }
        $premium = $this->premium($room, $night);
        $outcome = $this->protection->propose(
            $room,
            $this->calendar->row($room->code, $night),
            $inputs->override($room->code, $night),
            $anchor,
            $this->factors->factor($room, PricePlan::PublicRate, $this->rules->nightType($night)),
            $this->terms->standardFloor($room, $night)->amount,
            $this->protection->extraFloor($premium?->floor, $inputs->orders->targetFor($room->code, $night), $room->code),
            $this->protection->relationCeiling($premium?->ceiling),
        );
        $item = $outcome->item;
        if ($item === null || !$inputs->inFilter($room->code, $night)) {
            $this->line($room, $night, $item === null ? NightLine::PROTECTION_NONE : NightLine::OUT_OF_FILTER, ['code' => $outcome->code]);

            return;
        }
        $plan = $item->toPlanItem();
        $work = new WorkItem($room->code, $night, $this->calendar->row($room->code, $night)->price, $item->minPrice, $item->maxPrice, WorkItem::SOLD_OUT_PROTECTION, $plan->reasonParams, $this->before($room, $night), $premium?->orderRef, false, false, true);
        $this->add($work, NightLine::PROTECTION, ['floor_source' => $item->floorSource->value]);
    }

    private function paceStep(RoomType $room, DateTimeImmutable $night, int $k): void
    {
        $inputs = $this->calendar->inputs();
        $row = $this->calendar->row($room->code, $night);
        $anchor = $inputs->anchors->forNight($night);
        if ($anchor === null) {
            $this->line($room, $night, NightLine::NO_MARKET_ANCHOR, []);

            return;
        }
        [$paceSold, $paceUnits] = $this->calendar->paceBasis($room, $night);
        $terms = $this->terms->resolve($room, $night, $k, $this->rules->nightType($night), $row->sold, $row->total, $paceSold, $paceUnits, $this->premium($room, $night), $this->caps->capFor($room->code));
        $grouped = $paceSold !== $row->sold || $paceUnits !== $row->total;
        $decision = $this->engine->decide(new NightContext(
            $inputs->property,
            $room,
            $night,
            $row->price,
            $row->sold,
            $row->total,
            $anchor,
            $this->calendar->floorInForce($room, $night),
            $this->calendar->ceilingInForce($room, $night),
            null,
            $grouped ? $paceSold : null,
            $grouped ? $paceUnits : null,
            $terms->pace,
        ));
        if (!$decision->isStep()) {
            $this->line($room, $night, $decision->reasonCode, ['trace' => $decision->trace], $decision);

            return;
        }
        if (!$inputs->inFilter($room->code, $night)) {
            $this->line($room, $night, NightLine::OUT_OF_FILTER, ['trace' => $decision->trace], $decision);

            return;
        }
        $hold = $this->cadenceHold($room, $night, $decision, $paceSold, $paceUnits, $grouped, $terms->targetOrder);
        if ($hold !== null) {
            $this->line($room, $night, $hold, ['trace' => $decision->trace], $decision);

            return;
        }
        $step = new StepProposal(
            $room->code,
            $night,
            (string) $decision->reading?->status->value,
            $decision->direction,
            $row->price,
            (float) $decision->minPrice,
            (int) Num::roundHalfEven((float) $decision->targetSent, 0),
            $paceSold,
            $grouped ? SoldBase::Property : SoldBase::Room,
            $paceUnits,
        );
        $params = ['direction' => $decision->direction?->value, 'rule_trace' => $decision->trace, 'step' => $step->toArray()];
        $work = new WorkItem($room->code, $night, $row->price, $decision->minPrice, $decision->maxPrice, WorkItem::PACE_STEP, $params, $this->before($room, $night), $terms->citedOrder($decision->stepPrice), true);
        $this->add($work, $decision->reasonCode, ['trace' => $decision->trace], $decision);
    }

    /**
     * One step per night per day: the cadence of the ledger, with a new booking releasing one
     * more step on the sales ramp and a new owner order going at once (pace.cadencia_permite,
     * pace.ordem_nova_sem_passo).
     */
    private function cadenceHold(RoomType $room, DateTimeImmutable $night, PaceDecision $decision, int $sold, int $total, bool $grouped, ?string $targetOrder): ?string
    {
        $inputs = $this->calendar->inputs();
        $verdict = $this->cadence->permits(
            $inputs->ledger,
            $room->code,
            $night,
            $decision->direction ?? StepDirection::Up,
            $inputs->now,
            $this->rules->salesRampTop() !== null ? $sold : null,
            $grouped ? SoldBase::Property : SoldBase::Room,
            $total,
        );
        if ($verdict->allowed) {
            return null;
        }
        $order = $targetOrder === null ? null : $inputs->orders->find($targetOrder);
        if ($order !== null && $this->cadence->newOrderBypass($inputs->ledger, $room->code, $night, $order, $inputs->today)) {
            return null;
        }

        return $verdict->reason ?? NightLine::CADENCE;
    }

    /**
     * The bounds of the premium relations of a room on a night, before the coherence stage
     * (pace.regra_afrodite): above the dearest room of the set that sells, under its band.
     */
    private function premium(RoomType $room, DateTimeImmutable $night): ?PremiumCap
    {
        $cap = null;
        foreach ($this->graph->ordered() as $relation) {
            if ($relation->target !== $room->code || $relation->type !== RelationType::PremiumOverMax || !$relation->enabled || !$relation->params instanceof PremiumParams) {
                continue;
            }
            if (!$relation->scope->matches($night, $this->nightTypes->nightType($night), $this->calendar->inputs()->today)) {
                continue;
            }
            $bounds = PremiumBounds::forNight($relation, $this->graph->members($relation), $night, $this->facts, $this->nightTypes, $this->bands, $this->calendar->inputs()->today);
            $cap = new PremiumCap(max($bounds->floor, $cap?->floor ?? 0.0), $bounds->ceiling ?? $cap?->ceiling, $relation->orderRef);
        }

        return $cap;
    }

    /**
     * Keeps the item and its line. The floor of an item becomes the night's decided price for the
     * relations that read it, except a sold-out protection (the reference leaves those out).
     *
     * @param array<string, mixed> $params
     */
    private function add(WorkItem $item, string $code, array $params, ?PaceDecision $decision = null): void
    {
        $this->items[] = $item;
        $room = $this->calendar->inputs()->rooms[$item->room];
        if ($item->minPrice !== null && !$item->protection) {
            $this->facts->decide($item->room, $item->night, WorkItem::applied($item->minPrice, $room->base));
        }
        $this->lines[] = new NightLine($item->room, $item->night, $item->priceBefore, $code, $params, $item->minPrice, $item->maxPrice, $decision);
    }

    /**
     * @param array<string, mixed> $params
     */
    private function line(RoomType $room, DateTimeImmutable $night, string $code, array $params, ?PaceDecision $decision = null): void
    {
        $this->lines[] = new NightLine($room->code, $night, $this->calendar->row($room->code, $night)?->price, $code, $params, null, null, $decision);
    }

    private function before(RoomType $room, DateTimeImmutable $night): NightState
    {
        return NightState::from($this->calendar->row($room->code, $night), $this->calendar->inputs()->override($room->code, $night));
    }

    /**
     * Rooms in the order the relations need them: per property, a room after every room whose
     * price it reads; otherwise the configured order.
     *
     * @return list<RoomType>
     */
    private function roomOrder(): array
    {
        $rooms = array_values($this->calendar->inputs()->rooms);
        $depth = [];
        foreach ($rooms as $room) {
            $depth[$room->code] = $this->depth($room->code, []);
        }
        $position = array_flip(array_map(static fn (RoomType $r): string => $r->code, $rooms));
        usort($rooms, static fn (RoomType $a, RoomType $b): int => [$a->propertyId === $rooms[0]->propertyId ? 0 : 1, $depth[$a->code], $position[$a->code]]
            <=> [$b->propertyId === $rooms[0]->propertyId ? 0 : 1, $depth[$b->code], $position[$b->code]]);

        return $rooms;
    }

    /**
     * @param array<string, true> $seen
     */
    private function depth(string $room, array $seen): int
    {
        $deepest = 0;
        foreach ($this->graph->ordered() as $relation) {
            if ($relation->target !== $room || isset($seen[$room])) {
                continue;
            }
            foreach ($this->graph->priceInputs($relation) as $input) {
                $deepest = max($deepest, 1 + $this->depth($input, $seen + [$room => true]));
            }
        }

        return $deepest;
    }

    /**
     * @return array<string, true> rooms blocked on the night
     */
    private function blockedOn(DateTimeImmutable $night): array
    {
        $out = [];
        foreach ($this->calendar->inputs()->rooms as $code => $room) {
            if ($this->calendar->inputs()->isBlocked($code, $night)) {
                $out[$code] = true;
            }
        }

        return $out;
    }

    /**
     * @return array<string, float> room code => what stays on the channel on the night, once known
     */
    private function realsOn(DateTimeImmutable $night): array
    {
        $out = [];
        $suffix = '|' . Days::key($night);
        foreach ($this->strategyDecided as $key => $price) {
            if (str_ends_with($key, $suffix)) {
                $out[substr($key, 0, -strlen($suffix))] = $price;
            }
        }

        return $out;
    }

    private function key(RoomType $room, DateTimeImmutable $night): string
    {
        return $room->code . '|' . Days::key($night);
    }
}
