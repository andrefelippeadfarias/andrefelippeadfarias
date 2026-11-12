<?php

declare(strict_types=1);

namespace Ritmo\Domain\Model;

use DateTimeImmutable;
use InvalidArgumentException;
use Ritmo\Domain\Support\Days;

/**
 * One night of a listing as the pricing system has it after its last recalculation.
 */
final class PriceNight
{
    public readonly DateTimeImmutable $date;

    /**
     * @param float      $price           price the pricing system computed for the night (sent plan)
     * @param float|null $minPrice        floor in force for the night; null when unknown
     * @param float|null $maxPrice        ceiling in force for the night; null when there is none or unknown
     * @param int|null   $minStay         null when unknown
     * @param int        $sold            units sold
     * @param int        $total           units of the listing
     * @param bool       $hasDateOverride true when a date-specific override exists for the night
     *
     * @throws InvalidArgumentException on negative amounts or counts
     */
    public function __construct(
        DateTimeImmutable $date,
        public readonly float $price,
        public readonly ?float $minPrice,
        public readonly ?float $maxPrice,
        public readonly ?int $minStay,
        public readonly int $sold,
        public readonly int $total,
        public readonly bool $hasDateOverride = false,
    ) {
        foreach ([$price, $minPrice, $maxPrice] as $amount) {
            if ($amount !== null && (!is_finite($amount) || $amount < 0)) {
                throw new InvalidArgumentException('Prices cannot be negative.');
            }
        }
        if ($minStay !== null && $minStay < 1) {
            throw new InvalidArgumentException('The minimum stay must be at least one night.');
        }
        if ($sold < 0 || $total < 0) {
            throw new InvalidArgumentException('Unit counts cannot be negative.');
        }
        $this->date = Days::normalize($date);
    }

    /**
     * True when every unit is sold (an overbooked night counts as sold out).
     */
    public function isSoldOut(): bool
    {
        return $this->total > 0 && $this->sold >= $this->total;
    }
}
