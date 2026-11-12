<?php

declare(strict_types=1);

namespace Ritmo\Domain\Market;

use DateTimeImmutable;
use InvalidArgumentException;
use Ritmo\Domain\Support\Days;

/**
 * One observed PUBLIC price of a comparable competitor for one night (property currency).
 */
final class PriceSample
{
    public readonly DateTimeImmutable $night;

    public readonly DateTimeImmutable $capturedAt;

    /**
     * @param DateTimeImmutable $night       the night the price is for
     * @param float             $publicPrice price per night a guest sees, without member discounts
     * @param DateTimeImmutable $capturedAt  date-only: when the price was observed
     * @param string            $source      who the price belongs to (a competitor id); with it, only the
     *                                       newest sample of each source counts for a night. Empty when unknown
     *
     * @throws InvalidArgumentException when the price is not a positive finite number
     */
    public function __construct(
        DateTimeImmutable $night,
        public readonly float $publicPrice,
        DateTimeImmutable $capturedAt,
        public readonly string $source = '',
    ) {
        if (!is_finite($publicPrice) || $publicPrice <= 0) {
            throw new InvalidArgumentException('A price sample must be greater than zero.');
        }
        $this->night = Days::normalize($night);
        $this->capturedAt = Days::normalize($capturedAt);
    }
}
