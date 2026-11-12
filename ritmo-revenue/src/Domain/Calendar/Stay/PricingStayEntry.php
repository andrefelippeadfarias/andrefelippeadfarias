<?php

declare(strict_types=1);

namespace Ritmo\Domain\Calendar\Stay;

use DateTimeImmutable;
use Ritmo\Domain\Support\Days;

/**
 * One night of the pricing stay plan: what was found (issues and accepted reasons) and the items
 * for the pricing system's date override (a stay and/or arrival/departure masks), if any.
 */
final class PricingStayEntry
{
    public readonly DateTimeImmutable $night;

    /**
     * @param list<StayFinding> $findings issues first, in the order found
     */
    public function __construct(
        public readonly string $roomCode,
        DateTimeImmutable $night,
        public readonly array $findings,
        public readonly ?int $stayBefore = null,
        public readonly ?string $holiday = null,
        public readonly ?StayChange $stay = null,
        public readonly ?ArrivalDepartureChange $masks = null,
    ) {
        $this->night = Days::normalize($night);
    }

    /**
     * @return list<StayFinding>
     */
    public function issues(): array
    {
        return array_values(array_filter($this->findings, static fn (StayFinding $f): bool => !$f->isAccepted()));
    }

    /**
     * @return list<StayFinding>
     */
    public function accepted(): array
    {
        return array_values(array_filter($this->findings, static fn (StayFinding $f): bool => $f->isAccepted()));
    }

    public function hasItem(): bool
    {
        return $this->stay !== null || $this->masks !== null;
    }
}
