<?php

declare(strict_types=1);

namespace Ritmo\Domain\Relations\Port;

use DateTimeImmutable;
use Ritmo\Domain\Model\NightType;

/**
 * The night type of a date (weekday, weekend or holiday).
 */
interface NightCalendar
{
    public function nightType(DateTimeImmutable $night): NightType;
}
