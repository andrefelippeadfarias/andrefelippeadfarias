<?php

declare(strict_types=1);

namespace Ritmo\Domain\Pricing\Protection;

/**
 * Where the floor of a protection item really comes from; the reason shown to the owner names it.
 */
enum FloorSource: string
{
    /** The market percentile of the protection, converted to the sent plan. */
    case MarketPercentile = 'market_percentile';

    /** The standard floor of the night (above the market percentile). */
    case StandardFloor = 'standard_floor';

    /** The floor of a target order or a room relation (above the market percentile). */
    case ExtraFloor = 'extra_floor';

    /** The floor in force, higher than the protection floor, kept while the ceiling is released. */
    case LiveFloorKept = 'live_floor_kept';
}
