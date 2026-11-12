<?php

declare(strict_types=1);

namespace Ritmo\Domain\Model;

/**
 * The kind of night a date is. Curves, floors and price factors are configured per night type.
 */
enum NightType: string
{
    case Weekday = 'weekday';
    case Weekend = 'weekend';
    case Holiday = 'holiday';
}
