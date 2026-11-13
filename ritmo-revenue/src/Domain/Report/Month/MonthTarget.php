<?php

declare(strict_types=1);

namespace Ritmo\Domain\Report\Month;

use InvalidArgumentException;

/**
 * The owner's targets of one property for one month: the minimum revenue the owner wants and the
 * realistic target. Typed by the owner (revenue_targets); either may be missing.
 */
final class MonthTarget
{
    /**
     * @throws InvalidArgumentException on a negative or non-finite amount
     */
    public function __construct(public readonly ?float $minimum = null, public readonly ?float $realistic = null)
    {
        foreach ([$minimum, $realistic] as $amount) {
            if ($amount !== null && (!is_finite($amount) || $amount < 0)) {
                throw new InvalidArgumentException('A revenue target is a positive amount.');
            }
        }
    }

    /**
     * The minimum when it is set and above zero (a zero minimum means "no minimum").
     */
    public function minimumSet(): ?float
    {
        return $this->minimum !== null && $this->minimum > 0 ? $this->minimum : null;
    }
}
