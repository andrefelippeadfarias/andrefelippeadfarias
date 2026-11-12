<?php

declare(strict_types=1);

namespace Ritmo\Domain\Relations\Port;

/**
 * The floor of a room-night in the sent plan, and whether an owner floor order set it.
 */
final class FloorInForce
{
    public function __construct(
        public readonly float $value,
        public readonly bool $fromOrder = false,
    ) {
    }
}
