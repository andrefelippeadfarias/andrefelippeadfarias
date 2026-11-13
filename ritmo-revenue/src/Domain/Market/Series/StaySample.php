<?php

declare(strict_types=1);

namespace Ritmo\Domain\Market\Series;

use DateTimeImmutable;
use InvalidArgumentException;
use Ritmo\Domain\Support\Days;

/**
 * One observed price of one competitor for one stay (check-in, nights), for its cheapest room or
 * for a comparable room, captured at an instant. Never per night: the nights are derived.
 */
final class StaySample
{
    public const MAX_NIGHTS = 30;

    public readonly DateTimeImmutable $checkIn;

    /**
     * @param float|null        $total      the stay total as given; null when the price is per night
     * @param float             $price      the per-night price as given (or as stored)
     * @param DateTimeImmutable $capturedAt the instant the price was seen
     * @param int               $sequence   import order (later import, then later line, is higher): breaks capture ties
     *
     * @throws InvalidArgumentException on a stay or price that cannot be
     */
    public function __construct(
        public readonly int $competitorId,
        DateTimeImmutable $checkIn,
        public readonly int $nights,
        public readonly RoomBasis $basis,
        public readonly float $price,
        public readonly ?float $total,
        public readonly DateTimeImmutable $capturedAt,
        public readonly bool $available = true,
        public readonly int $sequence = 0,
    ) {
        if ($nights < 1 || $nights > self::MAX_NIGHTS) {
            throw new InvalidArgumentException('A stay has 1 to 30 nights.');
        }
        if (!is_finite($price) || $price <= 0 || ($total !== null && (!is_finite($total) || $total <= 0))) {
            throw new InvalidArgumentException('A stay price is above zero.');
        }
        $this->checkIn = Days::normalize($checkIn);
    }

    /**
     * The per-night price at full precision: the total divided by the nights when the total was
     * given (the reference divides the total), else the per-night price.
     */
    public function perNight(): float
    {
        return $this->total !== null ? $this->total / $this->nights : $this->price;
    }

    public function checkOut(): DateTimeImmutable
    {
        return Days::add($this->checkIn, $this->nights);
    }

    /**
     * "YYYY-MM-DD/<nights>": the stay the sample is for.
     */
    public function stayKey(): string
    {
        return Days::key($this->checkIn) . '/' . $this->nights;
    }
}
