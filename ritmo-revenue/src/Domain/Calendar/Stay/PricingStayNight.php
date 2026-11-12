<?php

declare(strict_types=1);

namespace Ritmo\Domain\Calendar\Stay;

use DateTimeImmutable;
use InvalidArgumentException;
use Ritmo\Domain\Model\DateOverride;
use Ritmo\Domain\Model\PriceNight;
use Ritmo\Domain\Support\Days;

/**
 * One night of the pricing calendar as the stay plan reads it: the minimum stay in force, the
 * units sold and total, and the listing's arrival/departure masks (seven characters 0/1, Monday to
 * Sunday; null when the pricing system reports none; masksEnabled false when they are switched off).
 */
final class PricingStayNight
{
    public readonly DateTimeImmutable $date;

    /**
     * @throws InvalidArgumentException on a stay below one night, negative units or an invalid mask
     */
    public function __construct(
        DateTimeImmutable $date,
        public readonly int $minStay,
        public readonly int $sold,
        public readonly int $total,
        public readonly ?string $checkinMask = null,
        public readonly ?string $checkoutMask = null,
        public readonly bool $masksEnabled = true,
    ) {
        if ($minStay < 1) {
            throw new InvalidArgumentException('The minimum stay must be at least one night.');
        }
        if ($sold < 0 || $total < 0) {
            throw new InvalidArgumentException('Units sold and total cannot be negative.');
        }
        foreach ([$checkinMask, $checkoutMask] as $mask) {
            if ($mask !== null && !DateOverride::isValidMask($mask)) {
                throw new InvalidArgumentException('A weekday mask has seven characters, each 0 or 1, Monday to Sunday.');
            }
        }
        $this->date = Days::normalize($date);
    }

    /**
     * From a night read by the pricing connector (a missing stay counts as one night).
     */
    public static function fromPriceNight(PriceNight $night, ?string $checkinMask = null, ?string $checkoutMask = null, bool $masksEnabled = true): self
    {
        return new self($night->date, max(1, $night->minStay ?? 1), $night->sold, $night->total, $checkinMask, $checkoutMask, $masksEnabled);
    }

    public function isSoldOut(): bool
    {
        return $this->total > 0 && $this->sold >= $this->total;
    }
}
