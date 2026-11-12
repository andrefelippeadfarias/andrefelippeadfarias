<?php

declare(strict_types=1);

namespace Ritmo\Application\ShadowRun;

use DateTimeImmutable;
use Ritmo\Domain\Market\AnchorLookup;
use Ritmo\Domain\Model\DateOverride;
use Ritmo\Domain\Model\InventoryNight;
use Ritmo\Domain\Model\PriceNight;
use Ritmo\Domain\Model\RoomType;
use Ritmo\Domain\Plan\NightState;
use Ritmo\Domain\Plan\PlanItem;
use Ritmo\Domain\Pricing\LedgerStep;
use Ritmo\Domain\Pricing\NightContext;
use Ritmo\Domain\Pricing\PaceBasis;
use Ritmo\Domain\Pricing\PaceEngine;
use Ritmo\Domain\Pricing\StepDirection;
use Ritmo\Domain\Support\Clock;
use Ritmo\Domain\Support\Days;
use Ritmo\Domain\Support\Num;
use Ritmo\Infrastructure\Db\Repository\InventorySnapshotRepository;
use Ritmo\Infrastructure\Db\Repository\MarketAnchorRepository;
use Ritmo\Infrastructure\Db\Repository\StepLedgerRepository;

/**
 * Runs the pace engine on every room night of the run. A night without a market anchor is left
 * as "no_market_data" without asking the engine; a night the PMS did not report, or without a
 * current price, is left out with its reason. The steps become the items of the plan.
 */
final class DecideStage implements Stage
{
    public const NAME = 'decide';

    public const NO_MARKET_DATA = 'no_market_data';

    public const NO_INVENTORY = 'no_inventory';

    public const NO_CURRENT_PRICE = 'no_current_price';

    /** Anchors this many days around today feed the monthly fallbacks of the lookup. */
    private const ANCHOR_WINDOW_DAYS = 365;

    public function __construct(
        private readonly InventorySnapshotRepository $snapshots,
        private readonly MarketAnchorRepository $anchors,
        private readonly StepLedgerRepository $ledger,
        private readonly Clock $clock,
    ) {
    }

    public function name(): string
    {
        return self::NAME;
    }

    public function run(RunContext $context): StageResult
    {
        $inventory = $this->inventory($context);
        $lookup = new AnchorLookup($context->rules, $this->anchors->range(
            $context->property->id,
            Days::add($context->today, -self::ANCHOR_WINDOW_DAYS),
            Days::add($context->today, self::ANCHOR_WINDOW_DAYS)
        ));
        $engine = new PaceEngine($context->rules, $this->clock);
        $basis = new PaceBasis($context->rules);
        $prices = $context->pricingNights();
        $overrides = $context->overrides();
        $sales = $this->sales($context->rooms, $inventory);

        $byReason = [];
        $items = [];
        foreach ($context->rooms as $room) {
            foreach ($context->horizon as $key => $night) {
                $inv = $inventory[$room->code][$key] ?? null;
                $override = $overrides[$room->code][$key] ?? null;
                $price = $inv === null ? null : self::priceNight($room, $inv, $prices[$room->code][$key] ?? null, $override);
                $anchor = $price === null ? null : $lookup->forNight($night);
                if ($price === null || $anchor === null) {
                    $reason = $inv === null ? self::NO_INVENTORY : ($price === null ? self::NO_CURRENT_PRICE : self::NO_MARKET_DATA);
                    $byReason[$reason] = ($byReason[$reason] ?? 0) + 1;
                    continue;
                }

                $group = $basis->groupFor($room, $context->rooms, $sales[$key] ?? []);
                $decision = $engine->decide(new NightContext(
                    $context->property,
                    $room,
                    $night,
                    $price->price,
                    $price->sold,
                    $price->total,
                    $anchor,
                    $price->minPrice,
                    $price->maxPrice,
                    $this->lastStep($room, $night),
                    $group[0] ?? null,
                    $group[1] ?? null,
                ));
                $byReason[$decision->reasonCode] = ($byReason[$decision->reasonCode] ?? 0) + 1;
                if ($decision->isStep()) {
                    $items[PlanItem::keyOf($room->code, $night)] = [
                        'item' => StateCodec::planItem(PlanItem::fromDecision($decision, NightState::from($price, $override))),
                        'trace' => $decision->trace,
                        'pace_status' => $decision->reading?->status->value,
                        'direction' => $decision->direction?->value,
                    ];
                }
            }
        }
        ksort($byReason);

        return new StageResult(
            [
                'nights' => array_sum($byReason),
                'steps' => count($items),
                'by_reason' => $byReason,
                'no_market_data' => $byReason[self::NO_MARKET_DATA] ?? 0,
            ],
            ['items' => $items],
        );
    }

    /**
     * What the engine reads for one room night: the price in force (from the pricing system's
     * calendar when it was read, else the price the PMS holds), the floor and ceiling in force
     * and the units sold according to the PMS calendar (a blocked unit counts as sold).
     */
    private static function priceNight(RoomType $room, InventoryNight $inventory, ?PriceNight $read, ?DateOverride $override): ?PriceNight
    {
        $sold = max(0, min($room->units, $room->units - $inventory->available));
        if ($read !== null) {
            return new PriceNight($inventory->date, $read->price, $read->minPrice, $read->maxPrice, $read->minStay, $sold, $room->units, $read->hasDateOverride);
        }
        if ($inventory->pmsPrice === null || $inventory->pmsPrice <= 0.0) {
            return null;
        }

        return new PriceNight(
            $inventory->date,
            $inventory->pmsPrice,
            self::bound($override?->minFixed, $override?->minPercent, $room->base),
            self::bound($override?->maxFixed, $override?->maxPercent, $room->base),
            $override?->minStay ?? $inventory->minStay,
            $sold,
            $room->units,
            $override !== null,
        );
    }

    /**
     * A floor or ceiling of an override as an amount: the fixed amount, else the percentage over
     * the listing base.
     */
    private static function bound(?float $fixed, ?float $percent, float $base): ?float
    {
        if ($fixed !== null) {
            return $fixed;
        }

        return $percent === null ? null : Num::percentToAmount($percent, $base, 2);
    }

    /**
     * The PMS calendar captured by this run.
     *
     * @return array<string, array<string, InventoryNight>> room code => 'YYYY-MM-DD' => night
     */
    private function inventory(RunContext $context): array
    {
        $inventory = [];
        foreach ($context->rooms as $room) {
            $inventory[$room->code] = $this->snapshots->latest($room->code, $context->horizon->first(), $context->horizon->last(), $context->capturedAt());
        }

        return $inventory;
    }

    /**
     * Units sold (an overbooked room counts as full) and on sale of every room on every night,
     * for the group pace of small rooms.
     *
     * @param list<RoomType>                                  $rooms
     * @param array<string, array<string, InventoryNight>>    $inventory
     *
     * @return array<string, array<string, array{int, int}>> 'YYYY-MM-DD' => room code => [sold, units]
     */
    private function sales(array $rooms, array $inventory): array
    {
        $sales = [];
        foreach ($rooms as $room) {
            foreach ($inventory[$room->code] ?? [] as $key => $night) {
                $sales[$key][$room->code] = [max(0, min($room->units, $room->units - $night->available)), $room->units];
            }
        }

        return $sales;
    }

    private function lastStep(RoomType $room, DateTimeImmutable $night): ?LedgerStep
    {
        $last = $this->ledger->lastStep($room->code, $night);
        if ($last === null) {
            return null;
        }

        return new LedgerStep(StepDirection::from($last['direction']), $last['recorded_at'], $last['reached_channel']);
    }
}
