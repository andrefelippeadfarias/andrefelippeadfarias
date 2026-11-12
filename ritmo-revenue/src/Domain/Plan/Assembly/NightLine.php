<?php

declare(strict_types=1);

namespace Ritmo\Domain\Plan\Assembly;

use DateTimeImmutable;
use Ritmo\Domain\Pricing\PaceDecision;
use Ritmo\Domain\Support\Days;

/**
 * One row of the nights grid of a run: what the engine did with a room night and why, with the
 * item's floor and ceiling when it proposed one. Every room night of the horizon with a calendar
 * row gets one, so the admin can show the reason of a night that did not move too.
 *
 * Reference: the "linhas" and "fora" lists of pace._plano.
 */
final class NightLine
{
    public const BLOCKED = 'night_blocked';
    public const FROZEN = 'manual_freeze';
    public const FIXED_PRICE = 'order_fixed_price';
    public const LADDER_PRICE = 'ladder_price';
    public const LADDER_HOLD = 'ladder_hold';
    public const PROTECTION = 'sold_out_protection';
    public const PROTECTION_NONE = 'sold_out_no_item';
    public const SOLD_OUT_NO_ANCHOR = 'sold_out_no_anchor';
    public const NO_MARKET_ANCHOR = 'no_market_anchor';
    public const OUT_OF_FILTER = 'out_of_night_filter';
    public const CADENCE = 'cadence_hold';

    public readonly DateTimeImmutable $night;

    /**
     * @param float|null           $price  the price in force (null when the calendar has no row)
     * @param string               $code   what happened (a constant above or a PaceDecision reason code)
     * @param array<string, mixed> $params details of the code (trace, orders, hold...)
     */
    public function __construct(
        public readonly string $room,
        DateTimeImmutable $night,
        public readonly ?float $price,
        public readonly string $code,
        public readonly array $params = [],
        public readonly ?float $minPrice = null,
        public readonly ?float $maxPrice = null,
        public readonly ?PaceDecision $decision = null,
    ) {
        $this->night = Days::normalize($night);
    }

    /**
     * The row as stored with the run (scalars and lists only).
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'room' => $this->room,
            'night' => Days::key($this->night),
            'price' => $this->price,
            'code' => $this->code,
            'min_price' => $this->minPrice,
            'max_price' => $this->maxPrice,
            'params' => $this->params,
        ];
    }
}
