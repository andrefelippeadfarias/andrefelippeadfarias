<?php

declare(strict_types=1);

namespace Ritmo\Tests\Unit\Domain\Plan\Golden;

use DateTimeImmutable;
use OutOfBoundsException;
use Ritmo\Domain\Plan\Port\StrategyGuard;
use Ritmo\Domain\Support\Days;

/**
 * The answers of the owner's strategy (the sales ladder behind its plan order) as the golden
 * generator recorded them with the reference engine's helpers: scope, last-minute market floor and
 * the weekend owner floors it replaces. A question the case did not record fails the test.
 */
final class RecordedStrategy implements StrategyGuard
{
    /**
     * @param array<string, mixed> $hooks the "hooks" of a golden case
     */
    public function __construct(private readonly array $hooks)
    {
    }

    public function backsStrategy(string $orderId): bool
    {
        return in_array($orderId, $this->hooks['strategy_orders'], true);
    }

    public function actsOn(string $orderId, string $roomCode, DateTimeImmutable $night): bool
    {
        return (bool) $this->answer('acts', $orderId . '|' . $roomCode . '|' . Days::key($night));
    }

    public function marketFloor(string $orderId, string $roomCode, DateTimeImmutable $night): ?float
    {
        $floor = $this->answer('market_floor', $orderId . '|' . $roomCode . '|' . Days::key($night));

        return $floor === null ? null : (float) $floor;
    }

    public function replacesOwnerFloors(string $roomCode, DateTimeImmutable $night): bool
    {
        return (bool) $this->answer('replaces_owner_floors', $roomCode . '|' . Days::key($night));
    }

    private function answer(string $question, string $key): mixed
    {
        if (!array_key_exists($key, $this->hooks[$question])) {
            throw new OutOfBoundsException(sprintf('The case recorded no answer to %s for %s.', $question, $key));
        }

        return $this->hooks[$question][$key];
    }
}
