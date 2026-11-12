<?php

declare(strict_types=1);

namespace Ritmo\Domain\Relations\Port;

use DateTimeImmutable;
use Ritmo\Domain\Relations\Relation;

/**
 * The market price an occupancy switch sends its target to.
 */
interface MarketTargetSource
{
    /** The market target of the room-night in the sent plan, or null when the market has no data for it. */
    public function sentPrice(Relation $relation, string $room, DateTimeImmutable $night): ?float;
}
