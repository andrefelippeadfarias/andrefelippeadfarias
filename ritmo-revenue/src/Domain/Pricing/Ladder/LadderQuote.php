<?php

declare(strict_types=1);

namespace Ritmo\Domain\Pricing\Ladder;

/**
 * The public price a ladder asks for the next unit of a room night, with how it got there.
 */
final class LadderQuote
{
    /**
     * @param float      $public  public price of the next unit
     * @param float|null $opening public opening price
     * @param float|null $ceiling public ceiling (null for a price derived from another room)
     * @param int        $steps   increments carried by the next unit
     * @param bool       $dropped the last-minute drop was applied
     * @param bool       $derived the price follows another room's price
     */
    public function __construct(
        public readonly float $public,
        public readonly ?float $opening,
        public readonly ?float $ceiling,
        public readonly int $steps,
        public readonly bool $dropped,
        public readonly bool $derived = false,
    ) {
    }
}
