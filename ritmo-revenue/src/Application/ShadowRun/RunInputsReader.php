<?php

declare(strict_types=1);

namespace Ritmo\Application\ShadowRun;

use DateTimeImmutable;
use Ritmo\Application\Market\MarketRulerAssembler;
use Ritmo\Application\Market\MarketSeriesService;
use Ritmo\Application\Run\WeeklyReference;
use Ritmo\Application\Verify\ThirdPartyWatch;
use Ritmo\Domain\Market\AnchorLookup;
use Ritmo\Domain\Pricing\Ladder\MarketRuler;
use Ritmo\Domain\Model\DateOverride;
use Ritmo\Domain\Model\InventoryNight;
use Ritmo\Domain\Model\PriceNight;
use Ritmo\Domain\Model\RoomType;
use Ritmo\Domain\Orders\OrderBook;
use Ritmo\Domain\Plan\Assembly\RunInputs;
use Ritmo\Domain\Plan\PlanItem;
use Ritmo\Domain\Pricing\RuleCode;
use Ritmo\Domain\Pricing\RuleInForce;
use Ritmo\Domain\Support\Days;
use Ritmo\Infrastructure\Db\Repository\InventorySnapshotRepository;
use Ritmo\Infrastructure\Db\Repository\MarketAnchorRepository;
use Ritmo\Infrastructure\Db\Repository\OrderRepository;
use Ritmo\Infrastructure\Db\Repository\StepLedgerRepository;

/**
 * What the plan assembly of a run reads, from what the run collected: the calendar of every room
 * night (the pricing system's when it was read, else the PMS price with the date rule in force),
 * the date rules, the market anchors, the market ruler of every series (MarketRulerAssembler), the step
 * ledger, the owner orders with the freezes of nights someone else changed, the nights closed in
 * the PMS without a booking and the filter of a light run (both left in the state by the watch
 * stage) and the listing values at the start of the week.
 */
final class RunInputsReader
{
    /** Anchors this many days around today feed the monthly fallbacks of the lookup. */
    private const ANCHOR_WINDOW_DAYS = 365;

    /** @var array<string, array{code: string, params: array<string, scalar|null>}> room-night key => trace entry */
    private array $priceTraces = [];

    public function __construct(
        private readonly InventorySnapshotRepository $snapshots,
        private readonly MarketAnchorRepository $anchors,
        private readonly StepLedgerRepository $ledger,
        private readonly OrderRepository $orders,
        private readonly MarketRulerAssembler $ruler,
        private readonly MarketSeriesService $marketSeries,
        private readonly ?ThirdPartyWatch $thirdParty = null,
        private readonly ?WeeklyReference $weekly = null,
    ) {
    }

    public function read(RunContext $context, DateTimeImmutable $now): RunInputs
    {
        $this->priceTraces = [];
        $prices = $context->pricingNights();
        $overrides = $context->overrides();
        $calendar = [];
        $readAt = [];
        foreach ($context->rooms as $room) {
            $inventory = $this->snapshots->latest($room->code, $context->horizon->first(), $context->horizon->last(), $context->capturedAt());
            foreach ($inventory as $key => $night) {
                $price = $this->priceNight($room, $night, $prices[$room->code][$key] ?? null, $overrides[$room->code][$key] ?? null);
                if ($price !== null) {
                    $calendar[$room->code][$key] = $price;
                }
            }
            if ($context->capturedAt() !== null) {
                $readAt[$room->code] = $context->capturedAt();
            }
        }

        $anchors = $this->anchors->range(
            $context->property->id,
            Days::add($context->today, -self::ANCHOR_WINDOW_DAYS),
            Days::add($context->today, self::ANCHOR_WINDOW_DAYS)
        );

        return new RunInputs(
            $context->property,
            $context->rooms,
            $context->today,
            $now,
            Days::diff($context->today, $context->horizon->last()),
            $calendar,
            $overrides,
            new AnchorLookup($context->rules, $anchors),
            $this->ledger->ledger($context->property->id, $context->horizon->first(), $context->horizon->last()),
            $this->book($context),
            $this->ruler($context),
            self::keys($context->state['blocked'] ?? null) ?? [],
            self::keys($context->state['light_filter'] ?? null),
            $readAt,
            $this->weekly?->of($context->property->id, $context->listings(), $context->today) ?? [],
        );
    }

    /**
     * The market ruler of the horizon: every configured series of the price files and the anchor
     * series "market" (spec 06, 1.6). The series are rebuilt first when their inputs changed
     * (another day, new samples, the market section).
     */
    private function ruler(RunContext $context): MarketRuler
    {
        $this->marketSeries->rebuildIfStale($context->property->id);

        return $this->ruler->of($context->property, $context->rules, $context->horizon->first(), $context->horizon->last());
    }

    /**
     * The owner orders in force, with the manual freezes of the nights someone else changed.
     */
    private function book(RunContext $context): OrderBook
    {
        $book = $this->orders->book($context->property->id, $context->today);
        foreach ($this->thirdParty?->orders($context->property) ?? [] as $order) {
            $book = $book->with($order);
        }

        return $book;
    }

    /**
     * Room-night keys a stage left in the state (blocked nights, the filter of a light run).
     *
     * @return array<string, true>|null null when the state holds none
     */
    private static function keys(mixed $value): ?array
    {
        if (!is_array($value)) {
            return null;
        }
        $keys = [];
        foreach ($value as $key) {
            if (is_string($key) && $key !== '') {
                $keys[$key] = true;
            }
        }

        return $keys;
    }

    /**
     * The trace entry of a price the rule in force moved, by room-night key (the price read and
     * the price the engine started from); only for the nights of the last read.
     *
     * @return array<string, array{code: string, params: array<string, scalar|null>}>
     */
    public function priceTraces(): array
    {
        return $this->priceTraces;
    }

    /**
     * The night as the engine reads it: the price in force (the pricing system's calendar when it
     * was read, else the price the PMS holds), the floor and ceiling in force and the units sold
     * of the PMS calendar (a blocked unit counts as sold). Null without a price.
     */
    private function priceNight(RoomType $room, InventoryNight $inventory, ?PriceNight $read, ?DateOverride $override): ?PriceNight
    {
        $sold = max(0, min($room->units, $room->units - $inventory->available));
        if ($read !== null) {
            [$price, $min, $max, $minStay] = [$read->price, $read->minPrice, $read->maxPrice, $read->minStay];
        } elseif ($inventory->pmsPrice !== null && $inventory->pmsPrice > 0.0) {
            [$price, $min, $max] = [$inventory->pmsPrice, RuleInForce::floor($override, $room->base), RuleInForce::ceiling($override, $room->base)];
            $minStay = $override?->minStay ?? $inventory->minStay;
        } else {
            return null;
        }
        $floor = RuleInForce::floor($override, $room->base);
        $ceiling = RuleInForce::ceiling($override, $room->base);
        $within = RuleInForce::price($price, $floor, $ceiling);
        if ($within !== $price) {
            $this->priceTraces[PlanItem::keyOf($room->code, $inventory->date)] = ['code' => RuleCode::PRICE_WITHIN_RULE_IN_FORCE, 'params' => [
                'read' => $price,
                'price' => $within,
                'floor_in_force' => $floor,
                'ceiling_in_force' => $ceiling,
            ]];
        }

        return new PriceNight($inventory->date, $price, $min, $max, $minStay, $sold, $room->units, $read?->hasDateOverride ?? $override !== null);
    }
}
