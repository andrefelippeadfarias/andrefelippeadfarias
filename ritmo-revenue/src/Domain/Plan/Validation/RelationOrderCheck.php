<?php

declare(strict_types=1);

namespace Ritmo\Domain\Plan\Validation;

use DateTimeImmutable;
use Ritmo\Domain\Plan\FreshCalendar;
use Ritmo\Domain\Plan\IssueSubject;
use Ritmo\Domain\Relations\Coherence\RelationReport;
use Ritmo\Domain\Relations\Params\AtLeastParams;
use Ritmo\Domain\Relations\Params\BetweenMode;
use Ritmo\Domain\Relations\Params\BetweenParams;
use Ritmo\Domain\Relations\Relation;
use Ritmo\Domain\Relations\RelationGraph;
use Ritmo\Domain\Relations\RelationStage;
use Ritmo\Domain\Support\Days;
use Ritmo\Domain\Support\Num;

/**
 * The order between related rooms in the plan as it will be written: an `at_least` target not
 * below its reference plus the margin, a `between` (path) target not above its high reference.
 *
 * The coherence stage sets the related rooms together; when the validator then refuses one of
 * them, the other one alone may break the relation (a target lowered with its reference, the
 * reference's item refused). Each room counts with the floor of its item as the pricing system
 * applies it, or its current price when the plan has no floor for it. A relation counts as broken
 * by the plan only when the plan leaves it broken by more than it was before; the item of the
 * target is refused, or, without one, the items of the references. The caller validates again
 * without them, as with any refused entry, until the plan holds.
 *
 * Exempt: relations outside the coherence stage, out of scope that night, the relation and night
 * the coherence stage already reported a warning on (it decided it knowingly, e.g. the target's
 * own floor above a band), a sold-out target, an `at_least` reference with fewer free units than
 * the relation asks for.
 *
 * Closes finding 12 of the M2 review (docs/VERIFY.md, section 7). The reference does not check
 * this after refusing items either.
 */
final class RelationOrderCheck
{
    /** The plan would leave two related rooms in the wrong order. */
    public const RELATION_BROKEN = 'relation_broken';

    public function __construct(
        private readonly RelationGraph $graph,
        private readonly ?FreshCalendar $calendar,
        private readonly ?RelationReport $report,
        private readonly DateTimeImmutable $today,
        private readonly IssueLog $log,
        private readonly float $epsilon = 0.005,
    ) {
    }

    /**
     * @param list<ItemFacts> $facts the items that passed the shape checks
     */
    public function check(array $facts): void
    {
        $byNight = [];
        foreach ($facts as $f) {
            if ($f->floor() !== null) {
                $byNight[Days::key($f->item->date)][$f->room->code] = $f;
            }
        }
        foreach ($byNight as $key => $items) {
            $night = Days::date($key);
            $type = reset($items)->nightType;
            foreach ($this->graph->orderedFor(RelationStage::Coherence) as $relation) {
                if ($relation->scope->matches($night, $type, $this->today) && !$this->decidedByCoherence($relation, $night)) {
                    $this->checkRelation($relation, $night, $items);
                }
            }
        }
    }

    /**
     * @param array<string, ItemFacts> $items room => item of the night
     */
    private function checkRelation(Relation $relation, DateTimeImmutable $night, array $items): void
    {
        $params = $relation->params;
        if ($params instanceof AtLeastParams) {
            $references = [$params->reference];
            if ($this->freeUnits($params->reference, $night) < $params->referenceMinUnitsLeft) {
                return;
            }
            $gap = fn (bool $after): ?float => $this->gapAtLeast($relation->target, $params, $night, $items, $after);
        } elseif ($params instanceof BetweenParams && $params->mode === BetweenMode::Path) {
            $references = [$params->lowReference, $params->highReference];
            $gap = fn (bool $after): ?float => $this->gapBetween($relation->target, $params, $night, $items, $after);
        } else {
            return;
        }
        $rooms = [$relation->target, ...$references];
        if (array_intersect($rooms, array_keys($items)) === [] || $this->isSoldOut($relation->target, $night)) {
            return;
        }
        $after = $gap(true);
        $before = $gap(false);
        if ($after === null || $after <= $this->epsilon || ($before !== null && $after <= $before + $this->epsilon)) {
            return;
        }
        $blamed = isset($items[$relation->target]) ? [$relation->target] : array_values(array_intersect($references, array_keys($items)));
        foreach ($blamed as $room) {
            $this->log->item($items[$room], true, self::RELATION_BROKEN, sprintf(
                'Relation %s: the plan would leave %s out of order with its reference by %.2f (an item it depends on was refused).',
                $relation->id,
                $relation->target,
                $after,
            ), ['relation' => $relation->id, 'target' => $relation->target, 'gap' => Num::roundHalfEven($after, 2)]);
        }
    }

    /**
     * How far the target is below what its reference requires (positive: broken), or null.
     *
     * @param array<string, ItemFacts> $items
     */
    private function gapAtLeast(string $target, AtLeastParams $params, DateTimeImmutable $night, array $items, bool $after): ?float
    {
        $value = $this->value($target, $night, $items, $after);
        $reference = $this->value($params->reference, $night, $items, $after);

        return $value === null || $reference === null ? null : $params->margin->applyTo($reference) - $value;
    }

    /**
     * How far the target is above the dearer of its references (positive: broken), or null.
     *
     * @param array<string, ItemFacts> $items
     */
    private function gapBetween(string $target, BetweenParams $params, DateTimeImmutable $night, array $items, bool $after): ?float
    {
        $value = $this->value($target, $night, $items, $after);
        $low = $this->value($params->lowReference, $night, $items, $after);
        $high = $this->value($params->highReference, $night, $items, $after);

        return $value === null || $low === null || $high === null ? null : $value - max($low, $high);
    }

    /**
     * The room's price that night: the floor of its item after the plan, else its current price.
     *
     * @param array<string, ItemFacts> $items
     */
    private function value(string $room, DateTimeImmutable $night, array $items, bool $after): ?float
    {
        if (isset($items[$room])) {
            return $after ? $items[$room]->floor() : $items[$room]->current();
        }

        return $this->calendar?->night($room, $night)?->price;
    }

    private function freeUnits(string $room, DateTimeImmutable $night): int
    {
        $row = $this->calendar?->night($room, $night);

        return $row === null ? PHP_INT_MAX : $row->free();
    }

    private function isSoldOut(string $room, DateTimeImmutable $night): bool
    {
        $row = $this->calendar?->night($room, $night);

        return $row !== null && $row->total > 0 && $row->free() === 0;
    }

    private function decidedByCoherence(Relation $relation, DateTimeImmutable $night): bool
    {
        foreach ($this->report?->warnings() ?? [] as $warning) {
            if ($warning->relationId === $relation->id && Days::key($warning->night) === Days::key($night)) {
                return true;
            }
        }

        return false;
    }
}
