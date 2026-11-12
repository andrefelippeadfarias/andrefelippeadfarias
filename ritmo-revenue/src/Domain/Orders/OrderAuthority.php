<?php

declare(strict_types=1);

namespace Ritmo\Domain\Orders;

use Closure;
use DateTimeInterface;

/**
 * Checks the owner order an item cites to go past a safety rule.
 *
 * An item that needs an exception (a fixed price, a floor under the standard floors...) must cite
 * an order that is in force for its room and night, that releases every exception the item needs
 * and, when the order backs a strategy, whose strategy acts on that room and night. Whatever it
 * cites, no item may touch a night frozen by a manual change other than the one it cites.
 *
 * Reference: validar_plano.Validador.validar_data (excecao "dono" + ordem_id) and ordens.congelada.
 * Generalisation: the reference accepts any order in force except two families (stay rules and the
 * strategy outside its scope); here an order authorises only what its releases list.
 */
final class OrderAuthority
{
    public const ORDER_ID_MISSING = 'ORDER_ID_MISSING';
    public const ORDER_NOT_IN_FORCE = 'ORDER_NOT_IN_FORCE';
    public const ORDER_DOES_NOT_RELEASE = 'ORDER_DOES_NOT_RELEASE';
    public const ORDER_OUT_OF_SCOPE = 'ORDER_OUT_OF_SCOPE';
    public const NIGHT_FROZEN_BY_OTHER_ORDER = 'NIGHT_FROZEN_BY_OTHER_ORDER';

    public function __construct(private readonly OrderBook $orders)
    {
    }

    /**
     * The reason the item is refused, or null when its authority holds.
     *
     * @param list<OrderRelease>                                        $needs exceptions the item needs (empty: none)
     * @param (Closure(OwnerOrder, string, DateTimeInterface): bool)|null $scope narrows where an order counts
     *                                                                         (for instance only where its strategy acts)
     */
    public function check(?string $orderId, string $room, DateTimeInterface $night, array $needs = [], ?Closure $scope = null): ?string
    {
        $frozen = $this->orders->manualFreeze($room, $night);
        if ($frozen !== null && $frozen->id !== $orderId) {
            return self::NIGHT_FROZEN_BY_OTHER_ORDER;
        }
        if ($needs === []) {
            return null;
        }
        if ($orderId === null || $orderId === '') {
            return self::ORDER_ID_MISSING;
        }
        $order = $this->orders->inForce($orderId, $room, $night);
        if ($order === null) {
            return self::ORDER_NOT_IN_FORCE;
        }
        foreach ($needs as $need) {
            if (!$order->releases($need)) {
                return self::ORDER_DOES_NOT_RELEASE;
            }
        }
        if ($scope !== null && !$scope($order, $room, $night)) {
            return self::ORDER_OUT_OF_SCOPE;
        }

        return null;
    }
}
