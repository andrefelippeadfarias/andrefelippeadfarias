<?php

declare(strict_types=1);

namespace Ritmo\Domain\Orders;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use InvalidArgumentException;
use Ritmo\Domain\Support\Days;

/**
 * One literal order of the owner: what it does (type), where (rooms, nights) and what it allows.
 *
 * Immutable. Build it with {@see OrderReader::read()} from stored data, or directly.
 *
 * Reference: ordens.py (cobre, ordem_valida) and dados/ordens_dono.json.
 */
final class OwnerOrder
{
    /** Stands for every room of the site in $rooms. */
    public const ALL_ROOMS = '*';

    public const ID_PATTERN = '/^[A-Za-z0-9][A-Za-z0-9_.-]{0,63}$/';

    public readonly DateTimeImmutable $from;

    public readonly DateTimeImmutable $to;

    public readonly ?DateTimeImmutable $givenOn;

    public readonly ?DateTimeImmutable $createdAt;

    /**
     * @param list<string>                     $rooms               room codes, or ["*"] for every room
     * @param DateTimeImmutable                $from                first night covered
     * @param DateTimeImmutable                $to                  last night covered (inclusive)
     * @param list<string>                     $nights              when not empty, the only nights (YYYY-MM-DD) it is in force
     * @param list<string>                     $excludedNights      nights moved to a newer order, for every room
     * @param array<string, list<string>>      $excludedNightsByRoom nights moved to a newer order, per room
     * @param DateTimeImmutable|null           $givenOn             date the owner gave the order
     * @param DateTimeImmutable|null           $createdAt           instant it was recorded (UTC)
     * @param list<OrderRelease>               $releases            what it allows
     * @param array<string, float>             $floors              room code => floor in the sent plan
     * @param float|null                       $percentile          market percentile of a target order
     * @param array<string, array<string, int>> $baseSold           room code => night => units already sold when the order changed the ruler
     *
     * @throws InvalidArgumentException on an invalid shape
     */
    public function __construct(
        public readonly string $id,
        public readonly OrderType $type,
        public readonly array $rooms,
        DateTimeImmutable $from,
        DateTimeImmutable $to,
        public readonly array $nights = [],
        public readonly array $excludedNights = [],
        public readonly array $excludedNightsByRoom = [],
        ?DateTimeImmutable $givenOn = null,
        ?DateTimeImmutable $createdAt = null,
        public readonly array $releases = [],
        public readonly array $floors = [],
        public readonly ?float $percentile = null,
        public readonly array $baseSold = [],
        public readonly TargetTerms $terms = new TargetTerms(),
        public readonly string $note = '',
    ) {
        if (preg_match(self::ID_PATTERN, $id) !== 1) {
            throw new InvalidArgumentException(sprintf('Invalid order id "%s".', $id));
        }
        if ($rooms === [] || !array_is_list($rooms)) {
            throw new InvalidArgumentException('An order covers at least one room, or "*".');
        }
        if ($to < $from) {
            throw new InvalidArgumentException('An order cannot end before it starts.');
        }
        $this->from = Days::normalize($from);
        $this->to = Days::normalize($to);
        $this->givenOn = $givenOn === null ? null : Days::normalize($givenOn);
        $this->createdAt = $createdAt?->setTimezone(new DateTimeZone('UTC'));
    }

    /**
     * The order covers the room and the night: the room is listed (or "*"), the night is within
     * from..to and was not moved to a newer order. The $nights restriction is not part of
     * coverage (as in the reference); {@see isInForceFor()} adds it.
     */
    public function covers(string $room, DateTimeInterface $night): bool
    {
        if (!in_array(self::ALL_ROOMS, $this->rooms, true) && !in_array($room, $this->rooms, true)) {
            return false;
        }
        $key = Days::key($night);
        if (in_array($key, $this->excludedNights, true) || in_array($key, $this->excludedNightsByRoom[$room] ?? [], true)) {
            return false;
        }
        $day = Days::normalize($night);

        return $this->from <= $day && $day <= $this->to;
    }

    /**
     * The night is one of the listed nights, or the order lists none.
     */
    public function listsNight(DateTimeInterface $night): bool
    {
        return $this->nights === [] || in_array(Days::key($night), $this->nights, true);
    }

    /**
     * The order may authorise something for the room and night: it covers them, lists the night
     * (when it lists nights) and is not a follow-up.
     *
     * Reference: ordens.ordem_valida.
     */
    public function isInForceFor(string $room, DateTimeInterface $night): bool
    {
        return $this->type !== OrderType::FollowUp && $this->covers($room, $night) && $this->listsNight($night);
    }

    public function releases(OrderRelease $release): bool
    {
        return in_array($release, $this->releases, true);
    }

    /**
     * The order's floor for the room in the sent plan, or null.
     */
    public function floorFor(string $room): ?float
    {
        return $this->floors[$room] ?? null;
    }

    /**
     * Units already sold on the night when the order was given, or null.
     */
    public function baseSoldFor(string $room, DateTimeInterface $night): ?int
    {
        return $this->baseSold[$room][Days::key($night)] ?? null;
    }
}
