<?php

declare(strict_types=1);

namespace Ritmo\Domain\Pricing\Terms;

use DateTimeImmutable;
use Ritmo\Domain\Config\RuleSet;
use Ritmo\Domain\Model\NightType;
use Ritmo\Domain\Model\PaceStatus;
use Ritmo\Domain\Model\RoomType;
use Ritmo\Domain\Orders\OrderBook;
use Ritmo\Domain\Orders\OrderRelease;
use Ritmo\Domain\Orders\OrdersConfig;
use Ritmo\Domain\Orders\OwnerOrder;
use Ritmo\Domain\Pricing\Floors;
use Ritmo\Domain\Pricing\HardFloor;
use Ritmo\Domain\Pricing\Ladder\LastMinuteMarketFloor;
use Ritmo\Domain\Pricing\Ladder\WeekendFloorOverride;
use Ritmo\Domain\Pricing\OrderSalesRamp;
use Ritmo\Domain\Pricing\PaceMeter;
use Ritmo\Domain\Pricing\PaceTerms;
use Ritmo\Domain\Support\Days;

/**
 * The terms of the pace step of a room night: what the owner's target and floor orders, the
 * sales ramp, a strategy's last-minute market floor and the bounds of a premium relation change.
 *
 * Reference: the pace branch of pace._plano (alvo_da_ordem, noite_fraca, percentil_com_vendas,
 * piso_com_ordem, piso_semana_mercado, the premium relation's floor and band, so_desce, passo_livre
 * and the sales ramp of a target order: piso_atras_da_ordem, degrau_atras_da_ordem,
 * limite_volta_degrau, piso_com_vendas).
 */
final class NightTermsResolver
{
    /** Labels of the floors this class sets, next to the Floors::* labels. */
    public const ORDER_FLOOR = 'order_floor';
    public const MARKET_FLOOR = 'market_floor';
    public const PREMIUM_FLOOR = 'relation_premium';

    private readonly PaceMeter $meter;

    public function __construct(
        private readonly RuleSet $rules,
        private readonly OrdersConfig $ordersConfig,
        private readonly Floors $floors,
        private readonly OrderBook $orders,
        private readonly ?LastMinuteMarketFloor $marketFloor = null,
        private readonly ?WeekendFloorOverride $weekendOverride = null,
        private readonly string $timezone = 'UTC',
    ) {
        $this->meter = new PaceMeter($rules);
    }

    /**
     * The standard floor of a room night: the hard floor of the rule set, with the weekend
     * override of a strategy (reference: pace.pisos_enviados).
     */
    public function standardFloor(RoomType $room, DateTimeImmutable $night): HardFloor
    {
        $floor = $this->floors->hardFloor($room, $night);

        return $this->weekendOverride?->standardFloor($floor, $room, $night, $this->orders) ?? $floor;
    }

    /**
     * The floor of the engine for a room night: a floor (or target) order's floor replaces the
     * standard floors, never below the safety limit (reference: pace.piso_com_ordem).
     *
     * @return array{floor: HardFloor, order: ?OwnerOrder}
     */
    public function floorWithOrders(RoomType $room, DateTimeImmutable $night, OrderBook $orders): array
    {
        $byOrder = $orders->floorFor($room->code, $night);
        if ($byOrder === null) {
            return ['floor' => $this->standardFloor($room, $night), 'order' => null];
        }
        $amount = max($byOrder['amount'], $this->floors->safetyLimit($room));

        return ['floor' => new HardFloor($amount, self::ORDER_FLOOR, [['label' => self::ORDER_FLOOR, 'amount' => $amount]]), 'order' => $byOrder['order']];
    }

    /**
     * @param int                $sold       units of the room sold that night
     * @param int                $units      units of the room on sale that night
     * @param int                $paceSold   units sold of the pace basis (the room, or its group)
     * @param int                $paceUnits  units of the pace basis
     * @param PremiumCap|null    $premium    the bounds of a premium relation on the room that night
     * @param float|null         $listingTop the chained listing top of the room
     */
    public function resolve(
        RoomType $room,
        DateTimeImmutable $night,
        int $daysOut,
        NightType $type,
        int $sold,
        int $units,
        int $paceSold,
        int $paceUnits,
        ?PremiumCap $premium = null,
        ?float $listingTop = null,
    ): NightTerms {
        $status = $this->meter->measure($type, $daysOut, $paceSold, $paceUnits)->status;
        $target = $this->orders->targetFor($room->code, $night);
        $rampTop = $this->rules->salesRampTop();
        $share = $paceUnits > 0 ? $paceSold / $paceUnits : 0.0;
        $ramp = $target !== null && $this->rampsWithSales($target) ? $this->rampFor($target, $room, $night, $daysOut, $status, $paceSold, $paceUnits) : null;
        $byOrder = $ramp !== null || ($target !== null && $this->isWeakNight($target, $status, $sold, $units));

        $percentile = null;
        if ($byOrder) {
            $percentile = $this->orders->percentileOf($target);
            if ($ramp !== null) {
                $percentile = self::ramp($percentile, $ramp->share, (float) $rampTop);
            }
        }

        $floorSet = $this->floorWithOrders($room, $night, $byOrder || $target === null ? $this->orders : $this->orders->withoutTargets());
        $floor = $floorSet['floor'];
        $floorOrder = $floorSet['order']?->id;
        $replacedFloor = $replacedOrder = null;
        $market = $this->marketFloor?->floorFor($room->code, $night, $daysOut, $this->orders);
        if ($market !== null && $market['amount'] < $floor->amount - 0.5) {
            [$replacedFloor, $replacedOrder] = [$floor->amount, $floorOrder];
            $floor = new HardFloor($market['amount'], self::MARKET_FLOOR, [['label' => self::MARKET_FLOOR, 'amount' => $market['amount']]]);
            $floorOrder = $market['order'];
        }

        $relationOrder = null;
        $cap = null;
        if ($premium !== null) {
            $raise = new HardFloor($premium->floor, self::PREMIUM_FLOOR, [...$floor->candidates, ['label' => self::PREMIUM_FLOOR, 'amount' => $premium->floor]]);
            if ($ramp !== null) {
                // The ramp may lower the floor first: the premium floor is compared with the ramp's.
                $ramp = $ramp->withRaise($raise);
            } elseif ($premium->floor > $floor->amount) {
                $floor = $raise;
                $relationOrder = $premium->orderRef;
            }
            if ($premium->ceiling !== null) {
                $cap = $premium->ceiling;
                $relationOrder = $premium->orderRef;
            }
        }

        $terms = new PaceTerms(
            percentile: $percentile,
            byOrder: $byOrder,
            rampTop: !$byOrder && $rampTop !== null ? $rampTop : null,
            rampShare: max(0.0, min(1.0, $share)),
            freeStep: $byOrder && $target->terms->freeStep && $ramp === null,
            ramp: $ramp,
            floor: $floor,
            ceilingCap: $cap,
            onlyDown: $byOrder && $target->terms->onlyDown,
            listingTop: $listingTop,
            compareApplied: $ramp !== null,
            lockCeilingDown: $byOrder && $target->terms->freeStep,
            lockCeilingAlways: $ramp !== null && $target->terms->freeStep,
        );

        return new NightTerms($terms, $floorOrder, $replacedFloor, $replacedOrder, $relationOrder, $byOrder ? $target->id : null, $premium?->orderRef);
    }

    /**
     * The sales ramp of a target order on a room night (reference: the escada_ordem branch of
     * pace._plano). $sold and $total are the units of the pace basis: the room, or its group.
     */
    public function rampFor(OwnerOrder $order, RoomType $room, DateTimeImmutable $night, int $daysOut, PaceStatus $status, int $sold, int $total): OrderSalesRamp
    {
        $standard = $this->standardFloor($room, $night)->amount;
        $belowStandard = $order->releases(OrderRelease::BelowStandardFloor);
        $top = max($this->floorWithOrders($room, $night, $this->orders->withoutTargets())['floor']->amount, $standard, $order->terms->top[$room->code] ?? 0.0);
        $fallback = $this->fallbackFloor($order, $room->code, $night, $daysOut, $sold);

        return new OrderSalesRamp(
            $order->id,
            $this->rampShare($room, $night, $sold, $total),
            $top,
            $standard,
            $belowStandard,
            $fallback === null || $belowStandard ? $fallback : max($fallback, $standard),
            $order->terms->descentFloors[$room->code] ?? [],
            $order->terms->descentMarks ?? $this->ordersConfig->descentMarks,
            $this->ordersConfig->descentReachedSlack,
            $this->isWeakNight($order, $status, $sold, $total),
        );
    }

    /**
     * The fallback floor of a target order: a night at most the trigger days away that sold
     * nothing since the order's base drops to it, once the order is old enough; null when the
     * trigger does not hold (reference: pace.piso_atras_da_ordem, before the standard floor).
     */
    private function fallbackFloor(OwnerOrder $order, string $room, DateTimeImmutable $night, int $daysOut, int $sold): ?float
    {
        $amount = $order->terms->fallbackFloors[$room] ?? null;
        if ($amount === null || $daysOut > ($order->terms->fallbackTriggerDays ?? $this->ordersConfig->fallbackTriggerDays)) {
            return null;
        }
        if ($sold > ($this->orders->baseSold($room, $night)['sold'] ?? 0)) {
            return null;
        }
        $minDays = $order->terms->fallbackMinDays ?? 0;
        if ($minDays > 0 && $order->createdAt !== null) {
            $today = Days::add($night, -$daysOut);
            if (Days::diff(Days::localDate($order->createdAt, $this->timezone), $today) < $minDays) {
                return null;
            }
        }

        return $amount;
    }

    /**
     * A night of a target order follows the order only while it sells weakly: the night with the
     * strong share sold, or ahead of its curve with the "ahead" share sold, stays with the engine
     * (reference: pace.noite_fraca, read with the room's own units).
     */
    public function isWeakNight(OwnerOrder $order, PaceStatus $status, int $sold, int $units): bool
    {
        $share = $units > 0 ? $sold / $units : 0.0;
        $strong = $order->terms->strongOccupancy ?? $this->ordersConfig->strongNightOccupancy;
        $strongAhead = $order->terms->strongAheadOccupancy ?? $this->ordersConfig->strongNightAheadOccupancy;

        return !($share >= $strong || ($status === PaceStatus::Ahead && $share >= $strongAhead));
    }

    private function rampsWithSales(OwnerOrder $order): bool
    {
        return $order->terms->salesRamp && $this->rules->salesRampTop() !== null;
    }

    /**
     * The share of the ramp of a target order: only the sales after the units it already had
     * (reference: vendidas_base_escada).
     */
    private function rampShare(RoomType $room, DateTimeImmutable $night, int $sold, int $total): float
    {
        $base = $this->orders->baseSold($room->code, $night);
        if ($base === null || $total === 0) {
            return $total > 0 ? $sold / $total : 0.0;
        }

        return max(0, $sold - $base['sold']) / max(1, $total - $base['sold']);
    }

    /**
     * Reference: pace.percentil_com_vendas.
     */
    private static function ramp(float $percentile, float $share, float $top): float
    {
        $share = max(0.0, min(1.0, $share));

        return $top > $percentile ? $percentile + ($top - $percentile) * $share : $percentile;
    }
}
