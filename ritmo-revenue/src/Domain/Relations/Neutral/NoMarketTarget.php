<?php

declare(strict_types=1);

namespace Ritmo\Domain\Relations\Neutral;

use DateTimeImmutable;
use Ritmo\Domain\Relations\Port\MarketTargetSource;
use Ritmo\Domain\Relations\Relation;

/**
 * No market data for switches: a met trigger falls through to the band and warns.
 */
final class NoMarketTarget implements MarketTargetSource
{
    public function sentPrice(Relation $relation, string $room, DateTimeImmutable $night): ?float
    {
        return null;
    }
}
