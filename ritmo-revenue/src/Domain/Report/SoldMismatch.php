<?php

declare(strict_types=1);

namespace Ritmo\Domain\Report;

use DateTimeImmutable;

/**
 * A night on which the PMS and the pricing system disagree on how many units are sold.
 */
final class SoldMismatch
{
    /**
     * @param int $pmsSold     units sold by the bookings of the PMS
     * @param int $pricingSold units the pricing system counts as sold
     */
    public function __construct(
        public readonly string $roomCode,
        public readonly DateTimeImmutable $night,
        public readonly int $pmsSold,
        public readonly int $pricingSold,
    ) {
    }

    /**
     * Pricing system minus PMS. Positive: the pricing system sees a sale the PMS does not show
     * (a block without a booking). Negative: a night sold in the PMS that the pricing system
     * still prices as free.
     */
    public function difference(): int
    {
        return $this->pricingSold - $this->pmsSold;
    }
}
