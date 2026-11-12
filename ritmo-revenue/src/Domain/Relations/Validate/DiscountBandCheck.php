<?php

declare(strict_types=1);

namespace Ritmo\Domain\Relations\Validate;

use DateTimeImmutable;
use Ritmo\Domain\Relations\Coherence\RelationReport;
use Ritmo\Domain\Relations\Coherence\RoomNight;
use Ritmo\Domain\Relations\Config\RelationSettings;
use Ritmo\Domain\Relations\Params\BandParams;
use Ritmo\Domain\Relations\Params\SwitchParams;
use Ritmo\Domain\Relations\Port\NightCalendar;
use Ritmo\Domain\Relations\Port\OrderGuard;
use Ritmo\Domain\Relations\Relation;
use Ritmo\Domain\Relations\RelationGraph;
use Ritmo\Domain\Relations\RelationStage;
use Ritmo\Domain\Support\Days;

/**
 * The validator rule of the discount bands (stage "validate"), on every night the plan has an item
 * of the target or of a member of the set.
 *
 * Above the cheapest free member is always an error (no order releases it), and so is a ceiling
 * above it. Above the upper edge is a warning. Below the lower edge is an error, or a warning when
 * an occupancy switch of the target holds the night with its trigger proven by the fresh calendar
 * (the item cites the switch's order, or the coherence stage decided that the switch is already
 * satisfied without an item), when an owner order releases the band, or when the target's value is
 * a percent floor compared with a reference that is not in the plan.
 */
final class DiscountBandCheck
{
    public function __construct(
        private readonly RelationGraph $graph,
        private readonly RelationSettings $settings,
        private readonly NightCalendar $calendar,
        private readonly OrderGuard $orders,
    ) {
    }

    /**
     * @return list<BandIssue>
     */
    public function check(PlanNightValues $values, ?RelationReport $report = null): array
    {
        $issues = [];
        foreach ($this->graph->orderedFor(RelationStage::Validate) as $band) {
            if (!$band->params instanceof BandParams) {
                continue;
            }
            foreach ($this->nightsOf($band, $values) as $night) {
                array_push($issues, ...$this->checkNight($band, $band->params, $night, $values, $report));
            }
        }

        return $issues;
    }

    /**
     * @return list<BandIssue>
     */
    private function checkNight(Relation $band, BandParams $params, DateTimeImmutable $night, PlanNightValues $values, ?RelationReport $report): array
    {
        $target = $this->value($band->target, $night, $values);
        $refs = [];
        foreach ($this->graph->members($band) as $member) {
            $value = $this->value($member, $night, $values);
            if ($value !== null && $this->isFree($member, $night, $values)) {
                $refs[] = [$value, isset($values->planned[RoomNight::key($member, $night)])];
            }
        }
        $room = $this->graph->room($band->target);
        if ($room === null || $target === null || $refs === [] || !$this->isFree($band->target, $night, $values)) {
            return [];
        }
        sort($refs);
        [$reference, $referencePlanned] = $refs[0];
        $upper = $reference * $params->upper();
        $issues = [];
        $issue = fn (string $code, bool $error): BandIssue => new BandIssue($code, $error, $band->id, $band->target, $night, ['value' => $target, 'reference' => $reference]);
        $ceiling = $values->ceilings[RoomNight::key($band->target, $night)] ?? null;
        if ($ceiling !== null && $ceiling > $reference + $this->settings->triggerAmount) {
            $issues[] = $issue(BandIssue::CEILING_ABOVE_REFERENCE, true);
        }
        if ($target > $reference + $this->settings->triggerAmount) {
            $issues[] = $issue(BandIssue::ABOVE_REFERENCE, true);

            return $issues;
        }
        if ($target > $upper + $this->settings->auditTolerance($room)) {
            $issues[] = $issue(BandIssue::ABOVE_UPPER, false);
        }
        if ($params->isDownOnly($this->calendar->nightType($night)) || $target >= $upper * $params->lowerRatio()) {
            return $issues;
        }
        $issues[] = $issue(...$this->belowLower($band, $night, $values, $report, $referencePlanned));

        return $issues;
    }

    /**
     * @return array{string, bool} code and whether it is an error
     */
    private function belowLower(Relation $band, DateTimeImmutable $night, PlanNightValues $values, ?RelationReport $report, bool $referencePlanned): array
    {
        $key = RoomNight::key($band->target, $night);
        $switch = $this->switchOf($band);
        if ($switch !== null && $switch->params instanceof SwitchParams && $this->triggerProven($switch, $switch->params, $night, $values)) {
            $cited = $switch->orderRef !== null && ($values->orderRefs[$key] ?? null) === $switch->orderRef;
            if ($cited || $report?->satisfiedBySwitch($band->target, $night) === $switch->id) {
                return [BandIssue::BELOW_BY_SWITCH, false];
            }
        }
        if ($this->orders->releases($band->id, $band->target, $night) !== null) {
            return [BandIssue::BELOW_BY_ORDER, false];
        }
        if (in_array($key, $values->percentFloors, true) && !$referencePlanned) {
            return [BandIssue::BELOW_CONFIRM_AFTER_RECALC, false];
        }

        return [BandIssue::BELOW_LOWER, true];
    }

    /**
     * The occupancy switch of the same target that suspends or caps the band.
     */
    private function switchOf(Relation $band): ?Relation
    {
        foreach ($this->graph->orderedFor(RelationStage::Validate) as $relation) {
            $params = $relation->params;
            if ($relation->target === $band->target && $params instanceof SwitchParams
                && ($params->capRelation === $band->id || in_array($band->id, $params->suspends, true))) {
                return $relation;
            }
        }

        return null;
    }

    /**
     * The trigger is proven only when every room of the trigger set is in the fresh calendar with
     * its total; any room missing means no proof.
     */
    private function triggerProven(Relation $switch, SwitchParams $params, DateTimeImmutable $night, PlanNightValues $values): bool
    {
        $sold = 0;
        $units = 0;
        foreach ($this->graph->triggerMembers($switch) as $member) {
            $fresh = $values->fresh[RoomNight::key($member, $night)] ?? null;
            $room = $this->graph->room($member);
            if ($fresh === null || $fresh->total === null || $room === null) {
                return false;
            }
            $sold += min($fresh->sold, $fresh->total);
            $units += $room->units;
        }

        return $params->isHit($sold, $units);
    }

    private function value(string $room, DateTimeImmutable $night, PlanNightValues $values): ?float
    {
        $key = RoomNight::key($room, $night);

        return $values->planned[$key] ?? ($values->fresh[$key] ?? null)?->price;
    }

    private function isFree(string $room, DateTimeImmutable $night, PlanNightValues $values): bool
    {
        return (($values->fresh[RoomNight::key($room, $night)] ?? null)?->freeUnits ?? 1) > 0;
    }

    /**
     * @return list<DateTimeImmutable>
     */
    private function nightsOf(Relation $band, PlanNightValues $values): array
    {
        $rooms = [$band->target, ...$this->graph->members($band)];
        $nights = [];
        foreach (array_keys($values->planned) as $key) {
            [$room, $night] = RoomNight::split((string) $key);
            if (in_array($room, $rooms, true)) {
                $nights[$night] = Days::date($night);
            }
        }
        ksort($nights);

        return array_values($nights);
    }
}
