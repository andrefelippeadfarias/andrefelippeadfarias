<?php

declare(strict_types=1);

namespace Ritmo\Domain\Relations\Params;

/**
 * What a discount band does when every room of its set is sold out: skip (the target keeps its own price) or use the cheapest member even sold out.
 */
enum EmptySetPolicy: string
{
    case Skip = 'skip';
    case UseSoldOut = 'use_sold_out';
}
