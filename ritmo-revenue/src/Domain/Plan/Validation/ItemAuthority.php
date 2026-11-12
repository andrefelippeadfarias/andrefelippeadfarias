<?php

declare(strict_types=1);

namespace Ritmo\Domain\Plan\Validation;

use Ritmo\Domain\Orders\OrderRelease;
use Ritmo\Domain\Orders\OwnerOrder;

/**
 * The owner order an item cites, once checked: in force for the room night and, when it backs a
 * strategy, inside the strategy's scope. An order allows what its releases list; an order behind a
 * strategy also allows what a strategy decides (floors under the standard ones, a larger rise)
 * where it acts.
 */
final class ItemAuthority
{
    private const STRATEGY_RELEASES = [OrderRelease::BelowStandardFloor, OrderRelease::AboveWeeklyRiseLimit];

    public function __construct(
        public readonly ?OwnerOrder $order = null,
        public readonly bool $backsStrategy = false,
    ) {
    }

    public function isValid(): bool
    {
        return $this->order !== null;
    }

    public function id(): ?string
    {
        return $this->order?->id;
    }

    public function allows(OrderRelease $release): bool
    {
        if ($this->order === null) {
            return false;
        }

        return $this->order->releases($release) || ($this->backsStrategy && in_array($release, self::STRATEGY_RELEASES, true));
    }
}
