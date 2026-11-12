<?php

declare(strict_types=1);

namespace Ritmo\Domain\Relations\Params;

/**
 * Unit of the threshold of an occupancy switch: a percent of the units of the set, or a number of units.
 */
enum TriggerUnit: string
{
    case Percent = 'percent';
    case Units = 'units';
}
