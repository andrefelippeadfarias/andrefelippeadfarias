<?php

declare(strict_types=1);

namespace Ritmo\Domain\Relations\Params;

/**
 * Lowest value of a derived price besides the hard floor of the room: none, or the quality floor.
 */
enum DeriveMinimum: string
{
    case None = 'none';
    case QualityFloor = 'quality_floor';
}
