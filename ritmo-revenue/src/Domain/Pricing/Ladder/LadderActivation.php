<?php

declare(strict_types=1);

namespace Ritmo\Domain\Pricing\Ladder;

use DateTimeInterface;
use Ritmo\Domain\Orders\OrderBook;

/**
 * Whether a strategy acts on a room night: it is enabled, the night is inside its period and,
 * when it depends on an owner order, that order exists, covers the room and the night (and lists
 * it, when it lists nights) and is not a follow-up. Deleting the order switches off everything
 * the strategy does: its prices, its weekend floor override, its last-minute floor.
 *
 * Reference: plano_dono.ativo and pace.ordem_do_plano.
 */
final class LadderActivation
{
    private function __construct()
    {
    }

    public static function isActive(LadderStrategy $strategy, string $room, DateTimeInterface $night, OrderBook $orders): bool
    {
        if (!$strategy->enabled || !$strategy->covers($night)) {
            return false;
        }

        return $strategy->authorityOrderId === null || $orders->inForce($strategy->authorityOrderId, $room, $night) !== null;
    }
}
