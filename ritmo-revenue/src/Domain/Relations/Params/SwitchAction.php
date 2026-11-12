<?php

declare(strict_types=1);

namespace Ritmo\Domain\Relations\Params;

/**
 * What the target of an occupancy switch does while the switch holds: go to a market price, or keep its own price.
 */
enum SwitchAction: string
{
    case MarketPercentile = 'market_percentile';
    case Hold = 'hold';
}
