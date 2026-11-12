<?php

declare(strict_types=1);

namespace Ritmo\Domain\Config;

use DateTimeInterface;
use Ritmo\Domain\Model\NightType;
use Ritmo\Domain\Support\DateRange;

/**
 * A holiday or special period of the calendar, as configured in "calendar.holidays".
 */
final class Holiday
{
    /**
     * @param DateRange  $nights    the nights of the period (first to last, inclusive)
     * @param NightType  $nightType how its nights are priced
     * @param int|null   $minStay   minimum stay of its own, or null
     * @param float|null $floor     floor of its own, in the price plan of the holiday floor, or null
     */
    public function __construct(
        public readonly string $name,
        public readonly DateRange $nights,
        public readonly NightType $nightType,
        public readonly ?int $minStay,
        public readonly ?float $floor,
    ) {
    }

    public function covers(DateTimeInterface $night): bool
    {
        return $this->nights->contains($night);
    }
}
