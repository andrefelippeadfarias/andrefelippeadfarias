<?php

declare(strict_types=1);

namespace Ritmo\Domain\Orders;

/**
 * Why owner orders take a room night out of the engine: the live price stands as it is.
 */
final class OrderGate
{
    /** The owner changed the night by hand in the pricing system and it is still frozen. */
    public const MANUAL_FREEZE = 'MANUAL_FREEZE';

    /** The owner fixed the price of the night by an order. */
    public const ORDER_FIXED_PRICE = 'ORDER_FIXED_PRICE';

    /**
     * @param list<string> $orderIds the orders that hold the night, in book order
     */
    public function __construct(
        public readonly string $code,
        public readonly array $orderIds,
    ) {
    }
}
