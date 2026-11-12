<?php

declare(strict_types=1);

namespace Ritmo\Tests\Unit\Domain\Relations\Golden;

use DateTimeImmutable;
use OutOfBoundsException;
use Ritmo\Domain\Relations\Coherence\RoomNight;
use Ritmo\Domain\Relations\Port\BetweenHints;
use Ritmo\Domain\Relations\Port\FloorInForce;
use Ritmo\Domain\Relations\Port\FloorSource;
use Ritmo\Domain\Relations\Port\MarketTargetSource;
use Ritmo\Domain\Relations\Port\NightCalendar;
use Ritmo\Domain\Relations\Port\OrderGuard;
use Ritmo\Domain\Relations\Port\PriceBandSource;
use Ritmo\Domain\Relations\Port\RelationHooks;
use Ritmo\Domain\Relations\Port\SentBand;
use Ritmo\Domain\Relations\Port\StrategyView;
use Ritmo\Domain\Relations\Relation;
use Ritmo\Domain\Support\Days;

/**
 * The answers of the other subsystems (floors, owner orders, sales ladder, market, bands) as the
 * golden generator recorded them with the reference engine's own helpers, per room-night. A
 * question the case did not record fails the test.
 */
final class RecordedHooks implements OrderGuard, StrategyView, FloorSource, PriceBandSource, MarketTargetSource
{
    /**
     * @param array<string, array<string, mixed>> $hooks          room-night key => answers
     * @param string|null                         $bandLastNight  null: no horizon limit
     * @param array<string, string>               $releases       room-night key => order releasing the band
     */
    public function __construct(
        private readonly array $hooks,
        private readonly ?string $bandLastNight = null,
        private readonly array $releases = [],
    ) {
    }

    /**
     * @param array<string, mixed> $input
     */
    public static function bundle(array $input, NightCalendar $calendar): RelationHooks
    {
        $hooks = new self($input['hooks'] ?? [], $input['band_last_night'] ?? null, $input['releases'] ?? []);

        return new RelationHooks($calendar, $hooks, $hooks, $hooks, $hooks, $hooks);
    }

    public function isFrozen(string $room, DateTimeImmutable $night): bool
    {
        return (bool) $this->at($room, $night)['frozen'];
    }

    public function hasOwnerPrice(string $room, DateTimeImmutable $night): bool
    {
        return (bool) $this->at($room, $night)['owner_price'];
    }

    public function floorOrderRef(string $room, DateTimeImmutable $night): ?string
    {
        return $this->at($room, $night)['floor_order_ref'];
    }

    public function isActive(string $orderId, string $room, DateTimeImmutable $night): bool
    {
        return in_array($orderId, $this->at($room, $night)['active_orders'], true);
    }

    public function releases(string $relationId, string $room, DateTimeImmutable $night): ?string
    {
        return $this->releases[RoomNight::key($room, $night)] ?? null;
    }

    public function pinsFloorToCeiling(string $room, DateTimeImmutable $night): bool
    {
        return (bool) $this->at($room, $night)['ladder_pin'];
    }

    public function governs(string $room, DateTimeImmutable $night): bool
    {
        return (bool) $this->at($room, $night)['governs'];
    }

    public function inForce(string $room, DateTimeImmutable $night): FloorInForce
    {
        $floor = $this->at($room, $night)['floor'];

        return new FloorInForce((float) $floor['value'], (bool) $floor['from_order']);
    }

    public function lowestAllowed(string $room, DateTimeImmutable $night): float
    {
        return (float) $this->at($room, $night)['lowest_allowed'];
    }

    public function betweenHints(Relation $relation, string $room, DateTimeImmutable $night): BetweenHints
    {
        $hints = $this->at($room, $night)['between'];

        return new BetweenHints(
            (float) $hints['own_floor'],
            (float) $hints['standard_floor'],
            $hints['lowering_order_ref'],
            $hints['own_target_ceiling'] === null ? null : (float) $hints['own_target_ceiling'],
            (bool) $hints['hold_current'],
        );
    }

    public function switchFloor(string $room, DateTimeImmutable $night): float
    {
        return (float) $this->at($room, $night)['switch_floor'];
    }

    public function ruleFloor(string $room, DateTimeImmutable $night, ?DateTimeImmutable $today): ?float
    {
        $hooks = $this->at($room, $night);

        return $today === null ? $hooks['rule_floor_owner'] : $hooks['rule_floor_today'];
    }

    public function safetyException(string $room, DateTimeImmutable $night): ?float
    {
        return $this->at($room, $night)['safety_exception'];
    }

    public function sentBand(string $room, DateTimeImmutable $night): ?SentBand
    {
        $band = $this->at($room, $night)['band'];

        return $band === null ? null : new SentBand((float) $band[0], (float) $band[1]);
    }

    public function lastNight(string $room, DateTimeImmutable $today): ?DateTimeImmutable
    {
        return $this->bandLastNight === null ? Days::date('9999-12-31') : Days::date($this->bandLastNight);
    }

    public function sentPrice(Relation $relation, string $room, DateTimeImmutable $night): ?float
    {
        $price = $this->at($room, $night)['market_sent'];

        return $price === null ? null : (float) $price;
    }

    /**
     * @return array<string, mixed>
     */
    private function at(string $room, DateTimeImmutable $night): array
    {
        return $this->hooks[RoomNight::key($room, $night)]
            ?? throw new OutOfBoundsException(sprintf('The case recorded no hook answer for %s.', RoomNight::key($room, $night)));
    }
}
