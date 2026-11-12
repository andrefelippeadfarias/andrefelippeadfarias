<?php

declare(strict_types=1);

namespace Ritmo\Domain\Plan\Assembly\Hooks;

use DateTimeImmutable;
use Ritmo\Domain\Pricing\Ladder\MarketRuler;
use Ritmo\Domain\Pricing\Ladder\SalesLadder;
use Ritmo\Domain\Relations\Params\SwitchParams;
use Ritmo\Domain\Relations\Port\MarketTargetSource;
use Ritmo\Domain\Relations\Relation;

/**
 * The market price an occupancy switch sends its room to, read from the market ruler: the series
 * and percentile its configuration gives for the ruler's night type, over the night's public
 * factor of the room's property (reference: plano_dono, the sent market price of the room without a tub).
 */
final class RulerMarketTarget implements MarketTargetSource
{
    public function __construct(private readonly MarketRuler $ruler, private readonly SalesLadder $ladder)
    {
    }

    public function sentPrice(Relation $relation, string $room, DateTimeImmutable $night): ?float
    {
        $params = $relation->params;
        $ruler = $this->ruler->night($night);
        if (!$params instanceof SwitchParams || $ruler === null) {
            return null;
        }
        $group = $params->byNightGroup[$ruler->type] ?? null;
        if ($group === null) {
            return null;
        }
        $value = $ruler->valueAt($group['segment'], $group['percentile']);

        return $value === null ? null : $value / $this->ladder->factor($room, $ruler);
    }
}
