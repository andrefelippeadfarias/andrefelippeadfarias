<?php

declare(strict_types=1);

namespace Ritmo\Domain\Pricing\Ladder;

use Closure;
use DateTimeImmutable;
use Ritmo\Domain\Model\PriceNight;
use Ritmo\Domain\Orders\OrderBook;
use Ritmo\Domain\Support\Days;

/**
 * Prices one night for every room a strategy governs, all at once (some rooms depend on others).
 *
 * A room is priced when a rule of the strategy matches it on the ruler's night type, it has a
 * calendar night, the strategy is active for it, and it is not blocked, frozen by a manual change
 * or fixed by an owner order (those keep their live price, which the relations then read). Rooms
 * with their own opening first, derived rooms after them; then the relations of the night.
 *
 * - The drop is skipped for nights that were already inside the drop window on the activation
 *   date (when configured); a derived room always uses the real days out.
 * - A sold-out room is priced too: as its last unit (the price a cancellation reopens).
 * - The sent price is the public price over the night's factor, never below the quality standard
 *   (when the rule applies it) nor the hard floor.
 * - A night of the period without a ruler entry is a ruler gap when the calendar classifier says
 *   the strategy would govern some room that night: the pace engine prices it and the gap is reported.
 *
 * Reference: pace.plano_da_noite.
 */
final class LadderNightEvaluator
{
    /**
     * @param (Closure(DateTimeImmutable): ?string)|null $fallbackNightType the night-type label of the calendar,
     *                                                                       used only to report ruler gaps
     */
    public function __construct(
        private readonly SalesLadder $ladder,
        private readonly MarketRuler $ruler,
        private readonly LadderHardFloor $hardFloors,
        private readonly LadderRelations $relations,
        private readonly ?Closure $fallbackNightType = null,
    ) {
    }

    /**
     * @param array<string, PriceNight> $rows    calendar nights of the night, by room code
     * @param array<string, true>       $blocked rooms blocked without a booking that night
     */
    public function evaluate(DateTimeImmutable $night, int $daysOut, array $rows, OrderBook $orders, array $blocked = []): LadderNight
    {
        $night = Days::normalize($night);
        if (!$this->ladder->strategy->covers($night)) {
            return new LadderNight($night);
        }
        $ruler = $this->ruler->night($night);
        if ($ruler === null) {
            return new LadderNight($night, [], $this->wouldGovern($night, $orders));
        }
        $out = new LadderNight($night);
        foreach ([false, true] as $derivedPass) {
            foreach ($this->ladder->roomCodes() as $room) {
                $rule = $this->ladder->ruleFor($room, $ruler->type);
                if ($rule === null || ($rule->derive !== null) !== $derivedPass || !isset($rows[$room]) || !$this->acts($room, $night, $orders, $blocked)) {
                    continue;
                }
                $row = $rows[$room];
                $quote = $derivedPass
                    ? $this->ladder->publicPrice($room, $ruler, $row->sold, $row->total, $daysOut, $out->quote($rule->derive->fromRoom)?->public)
                    : $this->ladder->publicPrice($room, $ruler, $row->sold, $row->total, $this->dropDays($night, $daysOut, $orders));
                if ($quote !== null) {
                    $out = $out->with($this->mount($room, $quote, $rule, $ruler, $row, $derivedPass));
                }
            }
        }

        return $this->relations->apply($out, new LadderReferences($rows, $blocked));
    }

    /**
     * The strategy acts on the room night: active, not blocked, not frozen, not fixed by order.
     *
     * @param array<string, true> $blocked
     */
    public function acts(string $room, DateTimeImmutable $night, OrderBook $orders, array $blocked = []): bool
    {
        return !isset($blocked[$room])
            && LadderActivation::isActive($this->ladder->strategy, $room, $night, $orders)
            && $orders->gateFor($room, $night) === null;
    }

    private function mount(string $room, LadderQuote $quote, LadderRule $rule, RulerNight $ruler, PriceNight $row, bool $derived): LadderRoomQuote
    {
        $roomType = $this->ladder->room($room);
        $hard = $this->hardFloors->for($roomType, $ruler->night);
        $sent = max($this->ladder->toSent($room, $ruler, $quote->public, $rule), $hard['amount']);
        $factor = $this->ladder->factor($room, $ruler);

        return new LadderRoomQuote(
            $room,
            $ruler->type,
            $sent,
            $sent * $factor,
            $quote->opening,
            $derived ? 0 : $quote->steps,
            $derived ? false : $quote->dropped,
            $row->sold,
            $row->total,
            $hard['amount'],
            $hard['label'],
            $factor,
            $derived,
        );
    }

    /**
     * The days out the drop test sees: nights already inside the drop window on the activation
     * date are kept out of it (when configured).
     */
    private function dropDays(DateTimeImmutable $night, int $daysOut, OrderBook $orders): int
    {
        $strategy = $this->ladder->strategy;
        $window = $strategy->drop->windowDays;
        $activation = $strategy->activatedOn
            ?? ($strategy->authorityOrderId === null ? null : $orders->find($strategy->authorityOrderId)?->givenOn)
            ?? $strategy->validFrom;
        if (!$strategy->drop->skipInsideWindowAtActivation || $activation === null || Days::add($night, -$window) >= $activation) {
            return $daysOut;
        }

        return $window + 1;
    }

    private function wouldGovern(DateTimeImmutable $night, OrderBook $orders): bool
    {
        $type = $this->fallbackNightType === null ? null : ($this->fallbackNightType)($night);
        if ($type === null) {
            return false;
        }
        foreach ($this->ladder->roomCodes() as $room) {
            if ($this->ladder->ruleFor($room, $type) !== null && LadderActivation::isActive($this->ladder->strategy, $room, $night, $orders)) {
                return true;
            }
        }

        return false;
    }
}
