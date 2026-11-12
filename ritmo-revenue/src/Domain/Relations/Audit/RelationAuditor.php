<?php

declare(strict_types=1);

namespace Ritmo\Domain\Relations\Audit;

use DateTimeImmutable;
use Ritmo\Domain\Relations\Coherence\DraftItem;
use Ritmo\Domain\Relations\Coherence\NightFacts;
use Ritmo\Domain\Relations\Coherence\Reason;
use Ritmo\Domain\Relations\Config\RelationSettings;
use Ritmo\Domain\Relations\Params\AtLeastParams;
use Ritmo\Domain\Relations\Params\BetweenParams;
use Ritmo\Domain\Relations\Params\PremiumParams;
use Ritmo\Domain\Relations\Port\RelationHooks;
use Ritmo\Domain\Relations\Relation;
use Ritmo\Domain\Relations\RelationGraph;
use Ritmo\Domain\Relations\RelationStage;
use Ritmo\Domain\Relations\RelationType;
use Ritmo\Domain\Support\DateRange;
use Ritmo\Domain\Support\Num;

/**
 * The daily audit of the CURRENT calendar prices over a long range: lists the nights a target is
 * out of its relation and proposes a floor and a ceiling for it. It is the safety net for the
 * nights the engine did not touch.
 *
 * Kept as in the reference engine (and different from the coherence stage): the middle room of a
 * between relation is out only beyond the whole gap widened by the audit tolerance; a night with
 * any of its three rooms sold out is not reported; a night missing in one of the three calendars
 * skips the whole group; the top room needs a free member that is not itself an audited target;
 * bounds are rounded half to even to whole currency.
 */
final class RelationAuditor
{
    private readonly PremiumAudit $premiumAudit;

    public function __construct(
        private readonly RelationGraph $graph,
        private readonly RelationSettings $settings,
        private readonly NightFacts $calendar,
        private readonly RelationHooks $hooks,
        private readonly DateTimeImmutable $today,
    ) {
        $this->premiumAudit = new PremiumAudit($settings, $calendar, $hooks, $today);
    }

    /**
     * @return list<AuditFinding> in night order, and in resolution order inside a night
     */
    public function run(DateRange $nights): array
    {
        $relations = $this->graph->orderedFor(RelationStage::Audit);
        $findings = [];
        foreach ($nights as $night) {
            $finals = [];
            $skippedGroups = [];
            foreach ($relations as $relation) {
                $group = implode(',', $this->graph->groupOf($relation->target));
                if (isset($skippedGroups[$group])) {
                    continue;
                }
                $finding = match ($relation->type) {
                    RelationType::Between => $this->between($relation, $night, $finals, $skippedGroups, $group),
                    RelationType::PremiumOverMax => $this->premium($relation, $relations, $night, $finals),
                    default => null,
                };
                if ($finding !== null) {
                    $findings[] = $finding;
                }
            }
        }

        return $findings;
    }

    /**
     * Plan items from the findings: the middle room cites the order that allows going below the
     * owner's floor when its floor is below it, else the relation's order; the top room cites none.
     *
     * @param list<AuditFinding> $findings
     *
     * @return list<DraftItem>
     */
    public function toPlanItems(array $findings): array
    {
        $items = [];
        foreach ($findings as $finding) {
            $relation = $this->graph->relation($finding->relationId);
            $orderRef = null;
            $code = Reason::AUDIT_PREMIUM;
            if ($finding->type === RelationType::Between) {
                $code = Reason::AUDIT_BETWEEN;
                $belowOwner = $finding->floor < $finding->ownerFloor - $this->settings->triggerAmount;
                $orderRef = $belowOwner ? $finding->loweringOrderRef : $relation?->orderRef;
            } elseif ($finding->ceiling !== null) {
                $code = Reason::AUDIT_BAND_CEILING;
            }
            $item = new DraftItem($finding->room, $finding->night, $finding->current, $finding->floor, $finding->ceiling, $orderRef);
            $item->addReason(new Reason($code, $finding->relationId, ['current' => $finding->current, 'compared' => $finding->compared]));
            $items[] = $item;
        }

        return $items;
    }

    /**
     * The audit of the middle room of a between relation on one night, or null when one of the
     * three rooms has no calendar row.
     */
    public function auditBetween(Relation $relation, DateTimeImmutable $night): ?AuditFinding
    {
        $params = $relation->params;
        if (!$params instanceof BetweenParams) {
            return null;
        }
        $lowRow = $this->calendar->row($params->lowReference, $night);
        $highRow = $this->calendar->row($params->highReference, $night);
        $targetRow = $this->calendar->row($relation->target, $night);
        if ($lowRow === null || $highRow === null || $targetRow === null) {
            return null;
        }
        $low = min($lowRow->price, $highRow->price);
        $high = max($lowRow->price, $highRow->price);
        $floors = $this->hooks->floors;
        $ownerFloor = $floors->ruleFloor($relation->target, $night, null) ?? 0.0;
        $ruleFloor = $floors->ruleFloor($relation->target, $night, $this->today) ?? 0.0;
        $lo = (float) Num::roundInt(max($ruleFloor, $low));
        $hi = (float) Num::roundInt(max($lo, $low + $params->positionMax * ($high - $low)));
        $tolerance = $this->settings->auditTolerance($this->calendar->room($relation->target));
        $current = $targetRow->price;
        $out = $current > $high + $tolerance || $current < $low - $tolerance || ($targetRow->minPrice ?? 0.0) > $high + $tolerance;
        $hints = $floors->betweenHints($relation, $relation->target, $night);

        return new AuditFinding(
            $relation->id, $relation->type, $relation->target, $night, $current, $lo, $hi, $low, $ownerFloor,
            $hints->loweringOrderRef, $out, $out ? min(max($current, $lo), $hi) : $current,
        );
    }

    /**
     * @param array<string, float> $finals        corrected values of the audited targets tonight
     * @param array<string, true>  $skippedGroups groups skipped tonight
     */
    private function between(Relation $relation, DateTimeImmutable $night, array &$finals, array &$skippedGroups, string $group): ?AuditFinding
    {
        $finding = $this->auditBetween($relation, $night);
        if ($finding === null) {
            $skippedGroups[$group] = true;

            return null;
        }
        $finals[$relation->target] = $finding->final;
        $params = $relation->params;
        foreach ($params instanceof BetweenParams ? [$params->lowReference, $params->highReference, $relation->target] : [] as $room) {
            if ($this->calendar->row($room, $night)?->isSoldOut()) {
                return null;
            }
        }

        return $finding->out ? $finding : null;
    }

    /**
     * @param list<Relation>       $relations audited relations
     * @param array<string, float> $finals
     */
    private function premium(Relation $relation, array $relations, DateTimeImmutable $night, array $finals): ?AuditFinding
    {
        $params = $relation->params;
        $row = $this->calendar->row($relation->target, $night);
        if (!$params instanceof PremiumParams || $row === null || $row->isSoldOut()) {
            return null;
        }
        $targets = array_map(static fn (Relation $audited): string => $audited->target, $relations);
        $open = [];
        $derived = [];
        foreach ($this->graph->members($relation) as $member) {
            $memberRow = $this->calendar->row($member, $night);
            if (!in_array($member, $targets, true)) {
                if ($memberRow !== null && !$memberRow->isSoldOut()) {
                    $open[] = $memberRow->price;
                }
            } elseif (isset($finals[$member])) {
                $derived[] = $memberRow !== null && $memberRow->isSoldOut() ? 0.0 : $finals[$member];
            }
        }
        if ($open === []) {
            return null;
        }

        $finding = $this->premiumAudit->check($relation, $params, self::deriveOf($relation, $relations), $night, max([...$open, ...$derived]));

        return $finding->out ? $finding : null;
    }

    /**
     * The derived at_least relation of the same target, if audited.
     *
     * @param list<Relation> $relations
     */
    private static function deriveOf(Relation $relation, array $relations): ?Relation
    {
        foreach ($relations as $candidate) {
            if ($candidate->target === $relation->target && $candidate->params instanceof AtLeastParams && $candidate->params->derive) {
                return $candidate;
            }
        }

        return null;
    }
}
