<?php

declare(strict_types=1);

namespace Ritmo\Domain\Calendar\Stay;

use DateTimeImmutable;
use Ritmo\Domain\Model\Restriction;
use Ritmo\Domain\Support\Days;

/**
 * One night of a closed package: its minimum and maximum stay and its arrival/departure
 * restriction, each optional.
 */
final class PackageNight
{
    public readonly DateTimeImmutable $date;

    public function __construct(
        DateTimeImmutable $date,
        public readonly ?int $minStay = null,
        public readonly ?int $maxStay = null,
        public readonly ?Restriction $restriction = null,
    ) {
        $this->date = Days::normalize($date);
    }
}
