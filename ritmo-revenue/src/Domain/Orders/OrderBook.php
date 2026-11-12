<?php

declare(strict_types=1);

namespace Ritmo\Domain\Orders;

use DateTimeInterface;
use InvalidArgumentException;

/**
 * The owner orders of a site, in the order they were recorded, and the questions the engine asks
 * them. Immutable.
 *
 * Precedence the engine applies (pace.py gate chain): a blocked night, then a night frozen by a
 * manual change, then a night whose price the owner fixed by order: the engine never touches
 * those nights and their live price stands. A floor or target order does not take the night out:
 * its floor replaces the standard floors (the lowest when several apply).
 *
 * Reference: ordens.py.
 */
final class OrderBook
{
    /** @var array<string, OwnerOrder> */
    private readonly array $orders;

    /**
     * @param list<OwnerOrder> $orders in the order they were recorded (ties are decided by it)
     *
     * @throws InvalidArgumentException on a repeated id
     */
    public function __construct(array $orders = [], private readonly float $defaultPercentile = 50.0)
    {
        $byId = [];
        foreach ($orders as $order) {
            if (isset($byId[$order->id])) {
                throw new InvalidArgumentException(sprintf('The order "%s" is listed twice.', $order->id));
            }
            $byId[$order->id] = $order;
        }
        $this->orders = $byId;
    }

    /**
     * @return list<OwnerOrder>
     */
    public function all(): array
    {
        return array_values($this->orders);
    }

    public function find(string $id): ?OwnerOrder
    {
        return $this->orders[$id] ?? null;
    }

    /**
     * The book with the order added at the end, replacing an order with the same id.
     */
    public function with(OwnerOrder $order): self
    {
        $others = array_values(array_filter($this->orders, static fn (OwnerOrder $o): bool => $o->id !== $order->id));

        return new self([...$others, $order], $this->defaultPercentile);
    }

    /**
     * The book without target orders: the engine uses it on a strong night, where a target
     * order's floor does not apply (pace.sem_alvo).
     */
    public function withoutTargets(): self
    {
        return new self(
            array_values(array_filter($this->orders, static fn (OwnerOrder $o): bool => $o->type !== OrderType::Target)),
            $this->defaultPercentile,
        );
    }

    /**
     * The order with that id when it exists and may authorise something for the room and night;
     * null otherwise (missing id, unknown order, not covering, not listing the night, follow-up).
     *
     * Reference: ordens.ordem_valida.
     */
    public function inForce(?string $id, string $room, DateTimeInterface $night): ?OwnerOrder
    {
        $order = $id === null ? null : $this->find($id);

        return $order !== null && $order->isInForceFor($room, $night) ? $order : null;
    }

    /**
     * The first order that covers the room night (and lists it, when it lists nights) and
     * releases what is asked, or null.
     *
     * Reference: validar_plano.Validador (libera regra_g: cobre and noites).
     */
    public function releasing(OrderRelease $release, string $room, DateTimeInterface $night): ?OwnerOrder
    {
        foreach ($this->orders as $order) {
            if ($order->releases($release) && $order->covers($room, $night) && $order->listsNight($night)) {
                return $order;
            }
        }

        return null;
    }

    /**
     * The first manual change still freezing the room night, or null (ordens.congelada).
     */
    public function manualFreeze(string $room, DateTimeInterface $night): ?OwnerOrder
    {
        foreach ($this->orders as $order) {
            if ($order->type === OrderType::Manual && $order->covers($room, $night)) {
                return $order;
            }
        }

        return null;
    }

    /**
     * Fixed-price orders covering the room night (ordens.ordens_por_data). Their nights list is
     * not consulted, as in the reference.
     *
     * @return list<OwnerOrder>
     */
    public function fixedPrice(string $room, DateTimeInterface $night): array
    {
        return array_values(array_filter(
            $this->orders,
            static fn (OwnerOrder $o): bool => $o->type === OrderType::FixedPrice && $o->covers($room, $night),
        ));
    }

    /**
     * Why orders keep the engine away from the room night, or null when they do not:
     * a manual freeze first, then a fixed price.
     */
    public function gateFor(string $room, DateTimeInterface $night): ?OrderGate
    {
        $frozen = $this->manualFreeze($room, $night);
        if ($frozen !== null) {
            return new OrderGate(OrderGate::MANUAL_FREEZE, [$frozen->id]);
        }
        $fixed = $this->fixedPrice($room, $night);
        if ($fixed !== []) {
            return new OrderGate(OrderGate::ORDER_FIXED_PRICE, array_map(static fn (OwnerOrder $o): string => $o->id, $fixed));
        }

        return null;
    }

    /**
     * The floor a floor or target order sets for the room night, the lowest when several do
     * (the first recorded on a tie); null when none does.
     *
     * Reference: ordens.piso_da_ordem.
     *
     * @return array{amount: float, order: OwnerOrder}|null
     */
    public function floorFor(string $room, DateTimeInterface $night): ?array
    {
        $found = null;
        foreach ($this->orders as $order) {
            if (($order->type !== OrderType::Floor && $order->type !== OrderType::Target) || !$order->covers($room, $night) || !$order->listsNight($night)) {
                continue;
            }
            $amount = $order->floorFor($room);
            if ($amount !== null && ($found === null || $amount < $found['amount'])) {
                $found = ['amount' => $amount, 'order' => $order];
            }
        }

        return $found;
    }

    /**
     * The target order of the room night, the one with the lowest percentile when several apply
     * (the first recorded on a tie); null when none does.
     *
     * Reference: ordens.alvo_da_ordem.
     */
    public function targetFor(string $room, DateTimeInterface $night): ?OwnerOrder
    {
        $found = null;
        foreach ($this->orders as $order) {
            if ($order->type !== OrderType::Target || !$order->covers($room, $night) || !$order->listsNight($night)) {
                continue;
            }
            if ($found === null || $this->percentileOf($order) < $this->percentileOf($found)) {
                $found = $order;
            }
        }

        return $found;
    }

    /**
     * The market percentile of a target order, or the configured default when it names none.
     */
    public function percentileOf(OwnerOrder $order): float
    {
        return $order->percentile ?? $this->defaultPercentile;
    }

    /**
     * Units the night had already sold when an order changed the ruler, from the first covering
     * order that records it; null when none does.
     *
     * Reference: ordens.vendidas_base_escada.
     *
     * @return array{sold: int, order: OwnerOrder}|null
     */
    public function baseSold(string $room, DateTimeInterface $night): ?array
    {
        foreach ($this->orders as $order) {
            if (!$order->covers($room, $night)) {
                continue;
            }
            $sold = $order->baseSoldFor($room, $night);
            if ($sold !== null) {
                return ['sold' => $sold, 'order' => $order];
            }
        }

        return null;
    }
}
