<?php

declare(strict_types=1);

namespace Ritmo\Domain\Report\Occupancy;

/**
 * What a row of the occupancy windows table totals.
 */
enum RowKind: string
{
    case Room = 'room';
    case Site = 'site';
    case Group = 'group';
    case Property = 'property';
}
