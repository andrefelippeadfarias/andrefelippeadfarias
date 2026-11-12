<?php

declare(strict_types=1);

namespace Ritmo\Domain\Pricing\Cadence;

/**
 * What the units sold of a step were counted over: the room type itself, or the property's pool
 * (small rooms read the property's occupancy).
 */
enum SoldBase: string
{
    case Room = 'room';
    case Property = 'property';
}
