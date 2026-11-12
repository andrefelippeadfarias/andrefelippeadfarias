<?php

declare(strict_types=1);

namespace Ritmo\Domain\Pricing\Terms;

use DateTimeImmutable;
use Ritmo\Domain\Config\RuleSet;
use Ritmo\Domain\Model\NightType;
use Ritmo\Domain\Model\PaceStatus;
use Ritmo\Domain\Model\RoomType;
use Ritmo\Domain\Orders\OrderBook;
use Ritmo\Domain\Orders\OrdersConfig;
use Ritmo\Domain\Orders\OwnerOrder;
use Ritmo\Domain\Pricing\Floors;
use Ritmo\Domain\Pricing\HardFloor;
use Ritmo\Domain\Pricing\Ladder\LastMinuteMarketFloor;
use Ritmo\Domain\Pricing\Ladder\WeekendFloorOverride;
use Ritmo\Domain\Pricing\PaceMeter;
use Ritmo\Domain\Pricing\PaceTerms;

/**
 * The terms of the pace step of a room night: what the owner's target and floor orders, the
 * sales ramp, a strategy's last-minute market floor and the bounds of a premium relation change.
 *
 * Reference: the pace branch of pace._plano (alvo_da_ordem, noite_fraca, percentil_com_vendas,
 * piso_com_ordem, piso_semana_mercado, regra_afrodite, so_desce, passo_livre).
 *
 * A target order whose terms carry a sales ramp of its own (the reference's "escada_vendas" order:
 * floor behind, descent steps and the ramp line) is read here as a plain target order whose
 * percentile climbs with the sales; the rest of that ramp is not part of milestone M2.
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
        $byOrder = $target !== null && ($this->rampsWithSales($target) || $this->isWeakNight($target, $status, $sold, $units));

        $percentile = null;
        if ($byOrder) {
            $percentile = $this->orders->percentileOf($target);
            if ($this->rampsWithSales($target)) {
                $percentile = self::ramp($percentile, $this->rampShare($room, $night, $paceSold, $paceUnits), (float) $rampTop);
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
            if ($premium->floor > $floor->amount) {
                $floor = new HardFloor($premium->floor, self::PREMIUM_FLOOR, [...$floor->candidates, ['label' => self::PREMIUM_FLOOR, 'amount' => $premium->floor]]);
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
            freeStep: $byOrder && $target->terms->freeStep && !$this->rampsWithSales($target),
            floor: $floor,
            ceilingCap: $cap,
            onlyDown: $byOrder && $target->terms->onlyDown,
            listingTop: $listingTop,
            compareApplied: $byOrder && $this->rampsWithSales($target),
            lockCeilingDown: $byOrder && $target->terms->freeStep,
            lockCeilingAlways: $byOrder && $target->terms->freeStep && $this->rampsWithSales($target),
        );

        return new NightTerms($terms, $floorOrder, $replacedFloor, $replacedOrder, $relationOrder, $byOrder ? $target->id : null);
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
