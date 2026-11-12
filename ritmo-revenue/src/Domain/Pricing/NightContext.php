<?php

declare(strict_types=1);

namespace Ritmo\Domain\Pricing;

use DateTimeImmutable;
use InvalidArgumentException;
use Ritmo\Domain\Model\MarketAnchor;
use Ritmo\Domain\Model\PriceNight;
use Ritmo\Domain\Model\Property;
use Ritmo\Domain\Model\RoomType;
use Ritmo\Domain\Support\Days;

/**
 * Everything the pace engine needs to decide one room night.
 */
final class NightContext
{
    public readonly DateTimeImmutable $night;

    /** Units sold used to place the night on its curve. */
    public readonly int $paceSold;

    /** Units the pace share is measured over. */
    public readonly int $paceUnits;

    /**
     * @param float             $currentPrice   price of the night now, in the sent plan
     * @param int               $sold           units of the room sold on the night
     * @param int               $units          units of the room on sale that night; the night is sold out when $sold reaches it
     * @param MarketAnchor|null $anchor         market anchor of the night (see AnchorLookup::forNight), null when there is none
     * @param float|null        $floorInForce   floor already in force for the night (sent plan); null or zero when unknown
     * @param float|null        $ceilingInForce ceiling already in force for the night (sent plan); null or zero when there is none
     * @param LedgerStep|null   $lastStep       most recent pace step recorded for this room night
     * @param int|null          $paceSold       units sold of the group the pace is measured over, when it is not the room
     *                                          alone (a room of one or two units read with the rest of its property); given
     *                                          together with $paceUnits. Defaults to $sold over the units of the room type
     *
     * @throws InvalidArgumentException on a room of another property, a negative amount or count, or half a pace basis
     */
    public function __construct(
        public readonly Property $property,
        public readonly RoomType $room,
        DateTimeImmutable $night,
        public readonly float $currentPrice,
        public readonly int $sold,
        public readonly int $units,
        public readonly ?MarketAnchor $anchor,
        public readonly ?float $floorInForce = null,
        public readonly ?float $ceilingInForce = null,
        public readonly ?LedgerStep $lastStep = null,
        ?int $paceSold = null,
        ?int $paceUnits = null,
    ) {
        if ($room->propertyId !== $property->id) {
            throw new InvalidArgumentException(sprintf('Room "%s" does not belong to property "%s".', $room->code, $property->id));
        }
        foreach ([$currentPrice, $floorInForce, $ceilingInForce] as $amount) {
            if ($amount !== null && (!is_finite($amount) || $amount < 0)) {
                throw new InvalidArgumentException('Prices cannot be negative.');
            }
        }
        if (($paceSold === null) !== ($paceUnits === null)) {
            throw new InvalidArgumentException('The pace basis needs both the units sold and the units on sale.');
        }
        foreach ([$sold, $units, $paceSold, $paceUnits] as $count) {
            if ($count !== null && $count < 0) {
                throw new InvalidArgumentException('Unit counts cannot be negative.');
            }
        }

        $this->night = Days::normalize($night);
        $this->paceSold = $paceSold ?? $sold;
        $this->paceUnits = $paceUnits ?? $room->units;
    }

    /**
     * The context of a night as the pricing system reports it.
     */
    public static function fromPriceNight(
        Property $property,
        RoomType $room,
        PriceNight $price,
        ?MarketAnchor $anchor,
        ?LedgerStep $lastStep = null,
    ): self {
        return new self($property, $room, $price->date, $price->price, $price->sold, $price->total, $anchor, $price->minPrice, $price->maxPrice, $lastStep);
    }

    /**
     * True when every unit on sale is sold (an overbooked night counts as sold out).
     */
    public function isSoldOut(): bool
    {
        return $this->units > 0 && $this->sold >= $this->units;
    }

    /**
     * True when the pace is measured over more than this room alone.
     */
    public function usesGroupPace(): bool
    {
        return $this->paceSold !== $this->sold || $this->paceUnits !== $this->room->units;
    }
}
