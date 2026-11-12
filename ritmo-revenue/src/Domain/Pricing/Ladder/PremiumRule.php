<?php

declare(strict_types=1);

namespace Ritmo\Domain\Pricing\Ladder;

/**
 * Inside a ladder night, a premium room kept above the dearest of other rooms that still sell, by
 * one rate on weekend and holiday nights and another on the other nights.
 */
final class PremiumRule
{
    /**
     * @param list<string> $over room codes the premium room stays above
     */
    public function __construct(
        public readonly string $room,
        public readonly array $over,
        public readonly float $rateWeekendOrHoliday,
        public readonly float $rateOther,
    ) {
    }
}
