<?php

declare(strict_types=1);

namespace Ritmo\Domain\Relations\Params;

/**
 * How a discount band writes the target: floor and ceiling pinned to one value, or floor at the lower edge and ceiling at the upper edge.
 */
enum WriteMode: string
{
    case Pin = 'pin';
    case Bounds = 'bounds';
}
