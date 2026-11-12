<?php

declare(strict_types=1);

namespace Ritmo\Domain\Calendar\Stay;

/**
 * A stay field that a pricing system may push to the PMS, overwriting what the PMS holds.
 */
enum StaySyncField: string
{
    case MinStay = 'min_stay';
    case MaxStay = 'max_stay';
    case ArrivalDeparture = 'arrival_departure';
}
