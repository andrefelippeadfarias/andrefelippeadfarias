<?php

declare(strict_types=1);

namespace Ritmo\Domain\Relations\Neutral;

use DateTimeImmutable;
use Ritmo\Domain\Relations\Port\OrderGuard;

/**
 * No owner orders: nothing is frozen, fixed, ordered or released.
 */
final class NoOrders implements OrderGuard
{
    public function isFrozen(string $room, DateTimeImmutable $night): bool
    {
        return false;
    }

    public function hasOwnerPrice(string $room, DateTimeImmutable $night): bool
    {
        return false;
    }

    public function floorOrderRef(string $room, DateTimeImmutable $night): ?string
    {
        return null;
    }

    public function isActive(string $orderId, string $room, DateTimeImmutable $night): bool
    {
        return false;
    }

    public function releases(string $relationId, string $room, DateTimeImmutable $night): ?string
    {
        return null;
    }
}
