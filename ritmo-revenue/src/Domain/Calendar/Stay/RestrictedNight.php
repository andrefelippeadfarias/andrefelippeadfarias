<?php

declare(strict_types=1);

namespace Ritmo\Domain\Calendar\Stay;

use DateTimeImmutable;
use Ritmo\Domain\Model\Restriction;
use Ritmo\Domain\Support\Days;

/**
 * A night with a restriction no stay rule asks for: reported, never changed.
 */
final class RestrictedNight
{
    public readonly DateTimeImmutable $night;

    public function __construct(DateTimeImmutable $night, public readonly Restriction $restriction)
    {
        $this->night = Days::normalize($night);
    }
}
