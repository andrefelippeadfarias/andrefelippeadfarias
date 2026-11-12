<?php

declare(strict_types=1);

namespace Ritmo\Domain\Relations\Port;

use DateTimeImmutable;

/**
 * What the owner's orders say about a room-night, as far as relations need it.
 */
interface OrderGuard
{
    /** A manual change by the owner is in force: nothing may touch the room-night. */
    public function isFrozen(string $room, DateTimeImmutable $night): bool;

    /** The owner fixed the price of the room-night by date. */
    public function hasOwnerPrice(string $room, DateTimeImmutable $night): bool;

    /** Id of the order that carries a floor by date for the room-night, or null. */
    public function floorOrderRef(string $room, DateTimeImmutable $night): ?string;

    /** The order exists, covers the room and the night and has not expired. */
    public function isActive(string $orderId, string $room, DateTimeImmutable $night): bool;

    /** Id of an order that releases the relation for the room-night, or null. */
    public function releases(string $relationId, string $room, DateTimeImmutable $night): ?string;
}
