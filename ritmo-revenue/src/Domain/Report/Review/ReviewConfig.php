<?php

declare(strict_types=1);

namespace Ritmo\Domain\Report\Review;

use InvalidArgumentException;

/**
 * Settings of the 12-month review (section reports.review).
 */
final class ReviewConfig
{
    /**
     * @param list<string> $priceTags rooms whose average sent price is shown; empty = every room
     *
     * @throws InvalidArgumentException on values outside their ranges
     */
    public function __construct(
        public readonly int $intervalDays = 15,
        public readonly float $aheadRatio = 1.2,
        public readonly float $behindRatio = 0.8,
        public readonly float $earlyMarketPercent = 5.0,
        public readonly array $priceTags = [],
    ) {
        if ($intervalDays < 1 || $aheadRatio < 1 || $behindRatio <= 0 || $behindRatio > 1 || $earlyMarketPercent < 0 || $earlyMarketPercent > 50) {
            throw new InvalidArgumentException('The review settings are outside their ranges.');
        }
    }

    public function reading(float $ownOccupancy, float $marketSoldPercent): Reading
    {
        if ($marketSoldPercent < $this->earlyMarketPercent) {
            return Reading::Early;
        }
        $ratio = 100 * $ownOccupancy / $marketSoldPercent;
        if ($ratio >= $this->aheadRatio) {
            return Reading::Ahead;
        }

        return $ratio <= $this->behindRatio ? Reading::Behind : Reading::OnPace;
    }
}
