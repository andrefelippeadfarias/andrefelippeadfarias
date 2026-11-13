<?php

declare(strict_types=1);

namespace Ritmo\Application\Write\Gate;

use Ritmo\Domain\Model\DateOverride;

/**
 * Tonight of one room as read in this run: the price the pricing system has for it now and the
 * date rule stored for it (null: no rule). The gate checks tonight against this, at the time it
 * issues the token.
 */
final class TonightState
{
    public function __construct(
        public readonly string $roomCode,
        public readonly ?float $price,
        public readonly ?DateOverride $rule,
    ) {
    }

    /**
     * The stored rule holds a ceiling or a price of its own: deleting it can let tonight rise.
     */
    public function ruleHoldsThePriceDown(): bool
    {
        return $this->rule !== null && ($this->rule->maxPercent !== null || $this->rule->maxFixed !== null || $this->rule->price !== null);
    }
}
