<?php

declare(strict_types=1);

namespace Ritmo\Domain\Plan\Assembly\Hooks;

use DateTimeImmutable;
use Ritmo\Domain\Model\RoomType;
use Ritmo\Domain\Orders\OrderBook;
use Ritmo\Domain\Plan\Assembly\LadderParts;
use Ritmo\Domain\Plan\PlanItem;
use Ritmo\Domain\Plan\Port\StrategyGuard;
use Ritmo\Domain\Relations\Coherence\RelationReport;
use Ritmo\Domain\Relations\Relation;
use Ritmo\Domain\Relations\RelationType;
use Ritmo\Domain\Support\Days;

/**
 * What the validator asks the run's sales ladder strategy about the order behind it. The order
 * authorises an item where the strategy acts: a room night it governs, a night of its last-minute
 * market floor, or a night where an occupancy switch citing the order held (proved by the
 * calendar of the run, as the relations report recorded it).
 *
 * Reference: validar_plano.Validador.escopo_plano and piso_mercado_do_plano.
 */
final class LadderStrategyGuard implements StrategyGuard
{
    /**
     * @param list<Relation>      $switches the occupancy switch relations of the property
     * @param array<string, true> $blocked  room nights closed in the PMS (PlanItem::keyOf)
     */
    public function __construct(
        private readonly LadderParts $ladder,
        private readonly OrderBook $orders,
        private readonly DateTimeImmutable $today,
        private readonly array $switches,
        private readonly RelationReport $report,
        private readonly array $blocked,
    ) {
    }

    public function backsStrategy(string $orderId): bool
    {
        return $orderId === $this->ladder->strategy->authorityOrderId;
    }

    public function actsOn(string $orderId, string $roomCode, DateTimeImmutable $night): bool
    {
        if (!$this->backsStrategy($orderId)) {
            return false;
        }
        $blocked = isset($this->blocked[PlanItem::keyOf($roomCode, $night)]) ? [$roomCode => true] : [];

        return $this->ladder->acts($roomCode, $night, $this->orders, $blocked)
            || $this->marketFloor($orderId, $roomCode, $night) !== null
            || $this->switchHeld($orderId, $roomCode, $night);
    }

    public function marketFloor(string $orderId, string $roomCode, DateTimeImmutable $night): ?float
    {
        if (!$this->backsStrategy($orderId)) {
            return null;
        }
        $floor = $this->ladder->marketFloor->floorFor($roomCode, $night, Days::diff($this->today, $night), $this->orders);

        return $floor['amount'] ?? null;
    }

    public function replacesOwnerFloors(string $roomCode, DateTimeImmutable $night): bool
    {
        $room = $this->roomOf($roomCode);

        return $room !== null && $this->ladder->weekendOverride->applies($room, $night, $this->orders);
    }

    private function switchHeld(string $orderId, string $roomCode, DateTimeImmutable $night): bool
    {
        foreach ($this->switches as $relation) {
            if ($relation->type === RelationType::OccupancySwitch && $relation->target === $roomCode && $relation->orderRef === $orderId
                && $this->report->outcomeOf($relation->id, $night)?->isSwitchHolding() === true) {
                return true;
            }
        }

        return false;
    }

    private function roomOf(string $code): ?RoomType
    {
        return in_array($code, $this->ladder->ladder->roomCodes(), true) ? $this->ladder->ladder->room($code) : null;
    }
}
