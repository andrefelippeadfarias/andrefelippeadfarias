<?php

declare(strict_types=1);

namespace Ritmo\Domain\Relations\Neutral;

use DateTimeImmutable;
use Ritmo\Domain\Relations\Port\StrategyView;

/**
 * No sales ladder: no night is priced by a strategy.
 */
final class NoStrategy implements StrategyView
{
    public function pinsFloorToCeiling(string $room, DateTimeImmutable $night): bool
    {
        return false;
    }

    public function governs(string $room, DateTimeImmutable $night): bool
    {
        return false;
    }
}
