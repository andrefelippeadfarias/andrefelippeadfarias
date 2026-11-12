<?php

declare(strict_types=1);

namespace Ritmo\Domain\Plan\Port;

use DateTimeImmutable;

/**
 * The answers of a site without pricing strategies: no order backs one, so every order in force
 * counts wherever it covers, and the owner floors always apply.
 */
final class NoStrategy implements StrategyGuard
{
    public function backsStrategy(string $orderId): bool
    {
        return false;
    }

    public function actsOn(string $orderId, string $roomCode, DateTimeImmutable $night): bool
    {
        return true;
    }

    public function marketFloor(string $orderId, string $roomCode, DateTimeImmutable $night): ?float
    {
        return null;
    }

    public function replacesOwnerFloors(string $roomCode, DateTimeImmutable $night): bool
    {
        return false;
    }
}
