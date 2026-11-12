<?php

declare(strict_types=1);

namespace Ritmo\Domain\Relations\Coherence;

use DateTimeImmutable;
use OutOfBoundsException;
use Ritmo\Domain\Model\PriceNight;
use Ritmo\Domain\Model\RoomType;
use Ritmo\Domain\Support\Days;

/**
 * What one run knows about the room-nights of a property: the calendar of the pricing system,
 * the prices decided in this run (changed by the relation passes), the prices the sales ladder
 * fixed, the light-run filter and the nights closed in the PMS without a reservation.
 *
 * A working model of one run: the decided prices are mutable, everything else is not.
 */
final class NightFacts
{
    /** @var array<string, RoomType> */
    private array $rooms = [];

    /** @var array<string, PriceNight> by room-night key */
    private array $calendar = [];

    /** @var array<string, true>|null */
    private ?array $filter;

    /** @var array<string, true> */
    private array $blocked;

    /**
     * @param list<RoomType>              $rooms
     * @param array<string, list<PriceNight>> $calendar        room code => calendar nights
     * @param array<string, float>        $decided         room-night key => price decided in this run (effective)
     * @param array<string, float>        $strategyDecided room-night key => price fixed by the sales ladder
     * @param list<string>|null           $lightFilter     room-night keys of a light run; null in a full run
     * @param list<string>                $blocked         room-night keys closed in the PMS without a reservation
     */
    public function __construct(
        array $rooms,
        array $calendar,
        private array $decided = [],
        private readonly array $strategyDecided = [],
        ?array $lightFilter = null,
        array $blocked = [],
    ) {
        foreach ($rooms as $room) {
            $this->rooms[$room->code] = $room;
        }
        foreach ($calendar as $code => $nights) {
            foreach ($nights as $night) {
                $this->calendar[RoomNight::key((string) $code, $night->date)] = $night;
            }
        }
        $this->filter = $lightFilter === null ? null : array_fill_keys($lightFilter, true);
        $this->blocked = array_fill_keys($blocked, true);
    }

    public function room(string $code): RoomType
    {
        return $this->rooms[$code] ?? throw new OutOfBoundsException(sprintf('Unknown room type "%s".', $code));
    }

    public function hasRoom(string $code): bool
    {
        return isset($this->rooms[$code]);
    }

    public function row(string $room, DateTimeImmutable $night): ?PriceNight
    {
        return $this->calendar[RoomNight::key($room, $night)] ?? null;
    }

    /**
     * The price a relation sees: none without a calendar row; else the price decided in this run;
     * else the ladder's price (in a light run only for a room-night of the filter); else the
     * calendar price.
     */
    public function price(string $room, DateTimeImmutable $night): ?float
    {
        $row = $this->row($room, $night);
        if ($row === null) {
            return null;
        }
        $key = RoomNight::key($room, $night);
        if (isset($this->decided[$key])) {
            return $this->decided[$key];
        }
        if (isset($this->strategyDecided[$key]) && ($this->filter === null || isset($this->filter[$key]))) {
            return $this->strategyDecided[$key];
        }

        return $row->price;
    }

    /** A calendar row exists and the night is not sold out (an overbooked night is sold out). */
    public function isFree(string $room, DateTimeImmutable $night): bool
    {
        $row = $this->row($room, $night);

        return $row !== null && !$row->isSoldOut();
    }

    public function isBlocked(string $room, DateTimeImmutable $night): bool
    {
        return isset($this->blocked[RoomNight::key($room, $night)]);
    }

    public function isFreeUnblocked(string $room, DateTimeImmutable $night): bool
    {
        return $this->isFree($room, $night) && !$this->isBlocked($room, $night);
    }

    public function unitsLeft(string $room, DateTimeImmutable $night): int
    {
        $row = $this->row($room, $night);

        return $row === null ? 0 : max(0, $row->total - $row->sold);
    }

    /** The floor in force of the calendar, null when missing or not above zero. */
    public function floorInForce(string $room, DateTimeImmutable $night): ?float
    {
        $floor = $this->row($room, $night)?->minPrice;

        return $floor !== null && $floor > 0 ? $floor : null;
    }

    /** The ceiling in force of the calendar, null when missing or not above zero. */
    public function ceilingInForce(string $room, DateTimeImmutable $night): ?float
    {
        $ceiling = $this->row($room, $night)?->maxPrice;

        return $ceiling !== null && $ceiling > 0 ? $ceiling : null;
    }

    public function decided(string $room, DateTimeImmutable $night): ?float
    {
        return $this->decided[RoomNight::key($room, $night)] ?? null;
    }

    public function decide(string $room, DateTimeImmutable $night, float $price): void
    {
        $this->decided[RoomNight::key($room, $night)] = $price;
    }

    public function forget(string $room, DateTimeImmutable $night): void
    {
        unset($this->decided[RoomNight::key($room, $night)]);
    }

    public function isStrategyDecided(string $room, DateTimeImmutable $night): bool
    {
        return isset($this->strategyDecided[RoomNight::key($room, $night)]);
    }

    public function isLightRun(): bool
    {
        return $this->filter !== null;
    }

    /**
     * @return array<string, float> room-night key => decided price
     */
    public function decidedPrices(): array
    {
        return $this->decided;
    }

    /**
     * The nights of the light-run filter.
     *
     * @return list<DateTimeImmutable>
     */
    public function filterNights(): array
    {
        return self::nightsOf(array_keys($this->filter ?? []));
    }

    /**
     * The nights with a decided price.
     *
     * @return list<DateTimeImmutable>
     */
    public function decidedNights(): array
    {
        return self::nightsOf(array_keys($this->decided));
    }

    /**
     * The nights of a room's calendar.
     *
     * @return list<DateTimeImmutable>
     */
    public function calendarNights(string $room): array
    {
        $nights = [];
        foreach ($this->calendar as $key => $row) {
            if (RoomNight::split($key)[0] === $room) {
                $nights[] = $row->date;
            }
        }

        return $nights;
    }

    /**
     * @param list<string> $keys
     *
     * @return list<DateTimeImmutable>
     */
    private static function nightsOf(array $keys): array
    {
        $nights = [];
        foreach ($keys as $key) {
            $nights[RoomNight::split((string) $key)[1]] = true;
        }

        return array_map(static fn (string $night): DateTimeImmutable => Days::date($night), array_keys($nights));
    }
}
