<?php

declare(strict_types=1);

namespace Ritmo\Domain\Report\Review;

/**
 * The market's sold occupancy of a month now and last year, in percent (typed by the owner or
 * imported; divergence RP-X5).
 */
final class MarketMonth
{
    public function __construct(public readonly ?float $soldPercent, public readonly ?float $lastYearPercent)
    {
    }
}
