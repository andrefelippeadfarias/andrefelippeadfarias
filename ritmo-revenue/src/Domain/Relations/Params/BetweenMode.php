<?php

declare(strict_types=1);

namespace Ritmo\Domain\Relations\Params;

/**
 * How a between relation places the target: on the way between two rooms, or between two percents of one room.
 */
enum BetweenMode: string
{
    case Path = 'path';
    case PercentOf = 'percent_of';
}
