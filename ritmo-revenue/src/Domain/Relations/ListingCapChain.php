<?php

declare(strict_types=1);

namespace Ritmo\Domain\Relations;

use OutOfBoundsException;
use Ritmo\Domain\Model\RoomType;
use Ritmo\Domain\Relations\Coherence\DraftItem;
use Ritmo\Domain\Relations\Coherence\PlanDraft;
use Ritmo\Domain\Relations\Coherence\Reason;
use Ritmo\Domain\Relations\Config\RelationSettings;
use Ritmo\Domain\Relations\Params\BetweenMode;
use Ritmo\Domain\Relations\Params\BetweenParams;
use Ritmo\Domain\Support\Num;

/**
 * The highest floor of each room under its listing maximum, chained through the between
 * relations: a target never above its high reference and a low reference never above the target.
 *
 * Floors go out as whole percents of the base, rounded down here, so the cap of a room is its
 * listing maximum (or the cap of the room it must not exceed, when lower) on its percent grid.
 */
final class ListingCapChain
{
    /** @var array<string, string> room => room it must not exceed */
    private array $above = [];

    public function __construct(private readonly RelationGraph $graph, private readonly RelationSettings $settings)
    {
        foreach ($graph->ordered() as $relation) {
            $params = $relation->params;
            if (!$params instanceof BetweenParams || $params->mode !== BetweenMode::Path) {
                continue;
            }
            $rooms = [$params->lowReference, $relation->target, $params->highReference];
            if (count(array_filter($rooms, fn (string $code): bool => $graph->room($code) !== null)) === 3) {
                $this->above[$relation->target] = $params->highReference;
                $this->above[$params->lowReference] = $relation->target;
            }
        }
    }

    /**
     * The highest effective floor of the room.
     */
    public function capFor(string $room): float
    {
        return $this->cap($room, []);
    }

    /**
     * The last pass over the plan: a floor above the cap is cut to it, and an item whose floor is
     * at the cap (within one grid step) gets its ceiling cut too, except a sold-out protection.
     *
     * @return list<DraftItem> the items whose floor was cut
     */
    public function apply(PlanDraft $draft): array
    {
        $capped = [];
        foreach ($draft->items() as $item) {
            $room = $this->graph->room($item->room);
            $floor = $item->floor();
            if ($room === null || $floor === null) {
                continue;
            }
            $cap = $this->capFor($item->room);
            if (Grid::effective($room, $floor) > $cap + 0.01) {
                $item->setFloor((float) Num::floorInt($cap));
                $item->addReason(new Reason(Reason::LISTING_CAP, null, ['cap' => $cap]));
                $capped[] = $item;
            }
            $this->capCeiling($item, $room, $cap);
        }

        return $capped;
    }

    private function capCeiling(DraftItem $item, RoomType $room, float $cap): void
    {
        $atTop = Grid::effective($room, (float) $item->floor()) >= $cap - $this->settings->auditBasePercent / 100 * $room->base;
        $ceiling = $item->ceiling();
        if ($atTop && !$item->protection && $ceiling !== null && $ceiling > $cap + 0.01) {
            $item->setCeiling(max((float) Num::floorInt($cap), (float) $item->floor()));
        }
    }

    /**
     * @param array<string, true> $seen
     */
    private function cap(string $code, array $seen): float
    {
        $room = $this->graph->room($code);
        if ($room === null) {
            throw new OutOfBoundsException(sprintf('Unknown room type "%s".', $code));
        }
        $limit = INF;
        if (isset($this->above[$code]) && !isset($seen[$code])) {
            $limit = $this->cap($this->above[$code], $seen + [$code => true]);
        }

        return Grid::effectiveDown($room, min($room->max, $limit));
    }
}
