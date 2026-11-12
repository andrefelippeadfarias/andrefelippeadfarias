<?php

declare(strict_types=1);

namespace Ritmo\Domain\Relations\Neutral;

use DateTimeImmutable;
use OutOfBoundsException;
use Ritmo\Domain\Config\RuleSet;
use Ritmo\Domain\Market\PriceFactor;
use Ritmo\Domain\Model\NightType;
use Ritmo\Domain\Model\RoomType;
use Ritmo\Domain\Pricing\Floors;
use Ritmo\Domain\Relations\Port\BetweenHints;
use Ritmo\Domain\Relations\Port\FloorInForce;
use Ritmo\Domain\Relations\Port\FloorSource;
use Ritmo\Domain\Relations\Relation;

/**
 * Floors without owner orders: every floor a relation asks for is the hard floor of the room
 * (listing minimum, safety limit, owner, quality and holiday floors), and a switched price never
 * goes below the safety limit nor, on a holiday, below the general holiday floor.
 */
final class HardFloorSource implements FloorSource
{
    /** @var array<string, RoomType> */
    private array $rooms = [];

    private readonly Floors $floors;

    private readonly PriceFactor $factors;

    /**
     * @param list<RoomType> $rooms
     */
    public function __construct(private readonly RuleSet $rules, array $rooms)
    {
        foreach ($rooms as $room) {
            $this->rooms[$room->code] = $room;
        }
        $this->factors = new PriceFactor($rules);
        $this->floors = new Floors($rules, $this->factors);
    }

    public function inForce(string $room, DateTimeImmutable $night): FloorInForce
    {
        return new FloorInForce($this->hard($room, $night));
    }

    public function lowestAllowed(string $room, DateTimeImmutable $night): float
    {
        return $this->hard($room, $night);
    }

    public function betweenHints(Relation $relation, string $room, DateTimeImmutable $night): BetweenHints
    {
        return BetweenHints::ofFloor($this->hard($room, $night));
    }

    public function switchFloor(string $room, DateTimeImmutable $night): float
    {
        $type = $this->rules->nightType($night);
        $safety = $this->floors->safetyLimit($this->room($room));
        $holiday = $this->rules->holidayFloor();
        if ($type !== NightType::Holiday || $holiday->amount <= 0.0) {
            return $safety;
        }

        return max($safety, $this->factors->toSent($holiday->amount, $holiday->pricePlan, $this->room($room), $type));
    }

    public function ruleFloor(string $room, DateTimeImmutable $night, ?DateTimeImmutable $today): ?float
    {
        return $this->hard($room, $night);
    }

    public function safetyException(string $room, DateTimeImmutable $night): ?float
    {
        return null;
    }

    private function hard(string $room, DateTimeImmutable $night): float
    {
        return $this->floors->hardFloor($this->room($room), $night)->amount;
    }

    private function room(string $code): RoomType
    {
        return $this->rooms[$code] ?? throw new OutOfBoundsException(sprintf('Unknown room type "%s".', $code));
    }
}
