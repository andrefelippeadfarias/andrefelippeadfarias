<?php

declare(strict_types=1);

namespace Ritmo\Domain\Relations\Neutral;

use Ritmo\Domain\Config\RuleSet;
use Ritmo\Domain\Market\PriceFactor;
use Ritmo\Domain\Model\RoomType;
use Ritmo\Domain\Relations\Config\RelationsConfig;
use Ritmo\Domain\Relations\Port\RelationHooks;

/**
 * The hooks of a site without owner orders, sales ladder or switch market data.
 */
final class NeutralHooks
{
    /**
     * @param list<RoomType> $rooms
     */
    public static function for(RuleSet $rules, RelationsConfig $config, array $rooms): RelationHooks
    {
        $calendar = new RuleSetNightCalendar($rules);
        $strategy = new NoStrategy();

        return new RelationHooks(
            $calendar,
            new NoOrders(),
            $strategy,
            new HardFloorSource($rules, $rooms),
            new ConfiguredPriceBands($config->priceBands, $rooms, new PriceFactor($rules), $calendar, $strategy),
            new NoMarketTarget(),
        );
    }
}
