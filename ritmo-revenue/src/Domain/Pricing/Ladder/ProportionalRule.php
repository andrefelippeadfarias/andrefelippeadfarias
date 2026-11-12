<?php

declare(strict_types=1);

namespace Ritmo\Domain\Pricing\Ladder;

use DateTimeImmutable;

/**
 * Settings of the proportional ladder mode: each booking moves the price along the line from the
 * price in force to a market top, in proportion to the units sold.
 */
final class ProportionalRule
{
    /**
     * @param string|null            $bandRoom            a room priced in a band below other rooms (its target follows
     *                                                    $bandReferenceRoom, its price stays under $bandCapRooms)
     * @param list<string>           $bandCapRooms        rooms whose cheapest free price caps the band room
     * @param float|null             $temporaryFloor      a lower cancellation floor in force until $temporaryFloorUntil,
     *                                                    in the price plan of the owner floors
     */
    public function __construct(
        public readonly float $fallbackStepPercent = 5.0,
        public readonly ?string $bandRoom = null,
        public readonly ?string $bandReferenceRoom = null,
        public readonly float $bandFactor = 1.0,
        public readonly array $bandCapRooms = [],
        public readonly float $bandCapDiscount = 0.0,
        public readonly ?float $temporaryFloor = null,
        public readonly ?DateTimeImmutable $temporaryFloorUntil = null,
    ) {
    }
}
