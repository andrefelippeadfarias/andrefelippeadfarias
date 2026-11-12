<?php

declare(strict_types=1);

namespace Ritmo\Domain\Pricing;

/**
 * The hard floor of a room on a night: the amount no price may go below, in the sent plan, and
 * the rule that set it.
 */
final class HardFloor
{
    /**
     * @param float                                     $amount     sent plan, two decimals
     * @param string                                    $label      one of the Floors::* labels: the rule that won
     * @param list<array{label: string, amount: float}> $candidates every floor that applied, in the sent plan
     */
    public function __construct(
        public readonly float $amount,
        public readonly string $label,
        public readonly array $candidates,
    ) {
    }
}
