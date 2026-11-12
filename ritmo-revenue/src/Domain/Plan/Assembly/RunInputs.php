<?php

declare(strict_types=1);

namespace Ritmo\Domain\Plan\Assembly;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use Ritmo\Domain\Market\AnchorLookup;
use Ritmo\Domain\Model\DateOverride;
use Ritmo\Domain\Model\PriceNight;
use Ritmo\Domain\Model\Property;
use Ritmo\Domain\Model\RoomType;
use Ritmo\Domain\Orders\OrderBook;
use Ritmo\Domain\Plan\PlanItem;
use Ritmo\Domain\Pricing\Cadence\StepLedger;
use Ritmo\Domain\Pricing\Ladder\MarketRuler;
use Ritmo\Domain\Support\Days;

/**
 * Everything one run reads to assemble the plan of a property, collected beforehand: no
 * connector, no repository, no clock here.
 *
 * The calendar is the pricing system's (price, floor and ceiling in force, units sold) or, when
 * that was not read, the PMS calendar mapped the same way. A light run names the room nights
 * that changed (new bookings and cancellations); a full run passes null.
 */
final class RunInputs
{
    public readonly DateTimeImmutable $today;

    public readonly DateTimeImmutable $now;

    /** @var array<string, RoomType> by code, in the order of the property's rooms */
    public readonly array $rooms;

    /**
     * @param list<RoomType>                                 $rooms       the property's room types
     * @param array<string, array<string, PriceNight>>       $calendar    room code => 'YYYY-MM-DD' => night
     * @param array<string, array<string, DateOverride>>     $overrides   room code => 'YYYY-MM-DD' => date rule in force
     * @param array<string, true>                            $blocked     room nights (PlanItem::keyOf) closed in the PMS without a booking
     * @param array<string, true>|null                       $lightFilter room nights of a light run; null for a full run
     * @param array<string, DateTimeImmutable>               $readAt      room code => instant its calendar was refreshed
     * @param array<string, array{base: float, min: float}>  $weeklyReference listing base and minimum at the start of the week
     *
     * @throws InvalidArgumentException on a room of another property, a negative horizon or a key that is not a room night
     */
    public function __construct(
        public readonly Property $property,
        array $rooms,
        DateTimeImmutable $today,
        DateTimeImmutable $now,
        public readonly int $days,
        public readonly array $calendar,
        public readonly array $overrides,
        public readonly AnchorLookup $anchors,
        public readonly StepLedger $ledger,
        public readonly OrderBook $orders,
        public readonly ?MarketRuler $ruler = null,
        public readonly array $blocked = [],
        public readonly ?array $lightFilter = null,
        public readonly array $readAt = [],
        public readonly array $weeklyReference = [],
    ) {
        if ($days < 0) {
            throw new InvalidArgumentException('The horizon of a run cannot be negative.');
        }
        $byCode = [];
        foreach ($rooms as $room) {
            if (!$room instanceof RoomType || $room->propertyId !== $property->id) {
                throw new InvalidArgumentException('Every room of a run belongs to its property.');
            }
            $byCode[$room->code] = $room;
        }
        foreach (array_keys($blocked + ($lightFilter ?? [])) as $key) {
            [$room] = explode('|', (string) $key, 2) + [1 => ''];
            if (!isset($byCode[$room]) || !Days::isValid(substr((string) $key, strlen($room) + 1))) {
                throw new InvalidArgumentException(sprintf('"%s" is not a room night of the property.', $key));
            }
        }
        $this->rooms = $byCode;
        $this->today = Days::normalize($today);
        $this->now = $now->setTimezone(new DateTimeZone('UTC'));
    }

    /**
     * The nights of the run, today first.
     *
     * @return list<DateTimeImmutable>
     */
    public function nights(): array
    {
        $nights = [];
        for ($k = 0; $k <= $this->days; ++$k) {
            $nights[] = Days::add($this->today, $k);
        }

        return $nights;
    }

    public function row(string $room, DateTimeImmutable $night): ?PriceNight
    {
        return $this->calendar[$room][Days::key($night)] ?? null;
    }

    public function override(string $room, DateTimeImmutable $night): ?DateOverride
    {
        return $this->overrides[$room][Days::key($night)] ?? null;
    }

    public function isBlocked(string $room, DateTimeImmutable $night): bool
    {
        return isset($this->blocked[PlanItem::keyOf($room, $night)]);
    }

    public function isLightRun(): bool
    {
        return $this->lightFilter !== null;
    }

    public function inFilter(string $room, DateTimeImmutable $night): bool
    {
        return $this->lightFilter === null || isset($this->lightFilter[PlanItem::keyOf($room, $night)]);
    }
}
