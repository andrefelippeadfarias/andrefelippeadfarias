<?php

declare(strict_types=1);

namespace Ritmo\Application\ShadowRun;

use DateTimeImmutable;
use Ritmo\Domain\Market\AnchorLookup;
use Ritmo\Domain\Model\DateOverride;
use Ritmo\Domain\Model\InventoryNight;
use Ritmo\Domain\Model\PriceNight;
use Ritmo\Domain\Model\RoomType;
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
 * What the plan assembly of a shadow run reads, from what the run collected: the calendar of
 * every room night (the pricing system's when it was read, else the PMS price with the date rule
 * in force), the date rules, the market anchors, the step ledger and the owner orders.
 *
 * A full run: no light-run filter. The market ruler of a sales ladder strategy and the nights
 * closed in the PMS without a booking have no source in this milestone, so no strategy acts and
 * no night is held as blocked.
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

        return new RunInputs(
            $context->property,
            $context->rooms,
            $context->today,
            $now,
            Days::diff($context->today, $context->horizon->last()),
            $calendar,
            $overrides,
            new AnchorLookup($context->rules, $this->anchors->range(
                $context->property->id,
                Days::add($context->today, -self::ANCHOR_WINDOW_DAYS),
                Days::add($context->today, self::ANCHOR_WINDOW_DAYS)
            )),
            $this->ledger->ledger($context->property->id, $context->horizon->first(), $context->horizon->last()),
            $this->orders->book($context->property->id, $context->today),
            null,
            [],
            null,
            $readAt,
        );
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
