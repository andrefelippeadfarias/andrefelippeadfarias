<?php

declare(strict_types=1);

namespace Ritmo\Tests\Unit\Domain\Relations;

use DateTimeImmutable;
use Ritmo\Domain\Relations\Coherence\RoomNight;
use Ritmo\Domain\Relations\Neutral\RuleSetNightCalendar;
use Ritmo\Domain\Relations\Port\BetweenHints;
use Ritmo\Domain\Relations\Port\FloorInForce;
use Ritmo\Domain\Relations\Port\FloorSource;
use Ritmo\Domain\Relations\Port\MarketTargetSource;
use Ritmo\Domain\Relations\Port\OrderGuard;
use Ritmo\Domain\Relations\Port\PriceBandSource;
use Ritmo\Domain\Relations\Port\RelationHooks;
use Ritmo\Domain\Relations\Port\SentBand;
use Ritmo\Domain\Relations\Port\StrategyView;
use Ritmo\Domain\Relations\Relation;
use Ritmo\Domain\Support\Days;

/**
 * Hook answers set by each test: every floor is 100 unless set, nothing is frozen or ordered, no
 * band and no market data. Room-night keys are "ROOM|YYYY-MM-DD".
 */
final class StubHooks implements OrderGuard, StrategyView, FloorSource, PriceBandSource, MarketTargetSource
{
    /** @var array<string, true> */
    public array $frozen = [];

    /** @var array<string, true> */
    public array $ownerPrice = [];

    /** @var array<string, string> */
    public array $floorOrders = [];

    /** @var array<string, list<string>> room-night => active order ids */
    public array $activeOrders = [];

    /** @var array<string, string> */
    public array $releases = [];

    /** @var array<string, FloorInForce> */
    public array $floors = [];

    /** @var array<string, float> */
    public array $lowest = [];

    /** @var array<string, BetweenHints> */
    public array $hints = [];

    /** @var array<string, float> */
    public array $switchFloors = [];

    /** @var array<string, SentBand> */
    public array $bands = [];

    public ?string $bandLastNight = null;

    /** @var array<string, float> */
    public array $market = [];

    /** @var array<string, true> */
    public array $governed = [];

    public function bundle(): RelationHooks
    {
        return new RelationHooks(new RuleSetNightCalendar(RelationsSample::rules()), $this, $this, $this, $this, $this);
    }

    public function isFrozen(string $room, DateTimeImmutable $night): bool
    {
        return isset($this->frozen[RoomNight::key($room, $night)]);
    }

    public function hasOwnerPrice(string $room, DateTimeImmutable $night): bool
    {
        return isset($this->ownerPrice[RoomNight::key($room, $night)]);
    }

    public function floorOrderRef(string $room, DateTimeImmutable $night): ?string
    {
        return $this->floorOrders[RoomNight::key($room, $night)] ?? null;
    }

    public function isActive(string $orderId, string $room, DateTimeImmutable $night): bool
    {
        return in_array($orderId, $this->activeOrders[RoomNight::key($room, $night)] ?? [], true);
    }

    public function releases(string $relationId, string $room, DateTimeImmutable $night): ?string
    {
        return $this->releases[RoomNight::key($room, $night)] ?? null;
    }

    public function pinsFloorToCeiling(string $room, DateTimeImmutable $night): bool
    {
        return false;
    }

    public function governs(string $room, DateTimeImmutable $night): bool
    {
        return isset($this->governed[RoomNight::key($room, $night)]);
    }

    public function inForce(string $room, DateTimeImmutable $night): FloorInForce
    {
        return $this->floors[RoomNight::key($room, $night)] ?? new FloorInForce(100.0);
    }

    public function lowestAllowed(string $room, DateTimeImmutable $night): float
    {
        return $this->lowest[RoomNight::key($room, $night)] ?? 100.0;
    }

    public function betweenHints(Relation $relation, string $room, DateTimeImmutable $night): BetweenHints
    {
        return $this->hints[RoomNight::key($room, $night)] ?? BetweenHints::ofFloor($this->inForce($room, $night)->value);
    }

    public function switchFloor(string $room, DateTimeImmutable $night): float
    {
        return $this->switchFloors[RoomNight::key($room, $night)] ?? 100.0;
    }

    public function ruleFloor(string $room, DateTimeImmutable $night, ?DateTimeImmutable $today): ?float
    {
        return $this->inForce($room, $night)->value;
    }

    public function safetyException(string $room, DateTimeImmutable $night): ?float
    {
        return null;
    }

    public function sentBand(string $room, DateTimeImmutable $night): ?SentBand
    {
        return $this->bands[RoomNight::key($room, $night)] ?? null;
    }

    public function lastNight(string $room, DateTimeImmutable $today): ?DateTimeImmutable
    {
        return $this->bandLastNight === null ? null : Days::date($this->bandLastNight);
    }

    public function sentPrice(Relation $relation, string $room, DateTimeImmutable $night): ?float
    {
        return $this->market[RoomNight::key($room, $night)] ?? null;
    }
}
