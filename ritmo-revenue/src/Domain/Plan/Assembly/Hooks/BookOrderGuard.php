<?php

declare(strict_types=1);

namespace Ritmo\Domain\Plan\Assembly\Hooks;

use DateTimeImmutable;
use Ritmo\Domain\Orders\OrderBook;
use Ritmo\Domain\Relations\Port\OrderGuard;

/**
 * What the owner's orders of the run say to the relations.
 *
 * No order releases a relation in milestone M2 (the reference's band release of one owner rule is
 * not an order release of the plugin), so releases() always answers null.
 */
final class BookOrderGuard implements OrderGuard
{
    public function __construct(private readonly OrderBook $orders)
    {
    }

    public function isFrozen(string $room, DateTimeImmutable $night): bool
    {
        return $this->orders->manualFreeze($room, $night) !== null;
    }

    public function hasOwnerPrice(string $room, DateTimeImmutable $night): bool
    {
        return $this->orders->fixedPrice($room, $night) !== [];
    }

    public function floorOrderRef(string $room, DateTimeImmutable $night): ?string
    {
        return $this->orders->floorFor($room, $night)['order']->id ?? null;
    }

    public function isActive(string $orderId, string $room, DateTimeImmutable $night): bool
    {
        return $this->orders->inForce($orderId, $room, $night) !== null;
    }

    public function releases(string $relationId, string $room, DateTimeImmutable $night): ?string
    {
        return null;
    }
}
