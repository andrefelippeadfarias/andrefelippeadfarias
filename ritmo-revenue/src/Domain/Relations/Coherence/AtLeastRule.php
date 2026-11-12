<?php

declare(strict_types=1);

namespace Ritmo\Domain\Relations\Coherence;

use DateTimeImmutable;
use LogicException;
use Ritmo\Domain\Relations\Params\AtLeastParams;
use Ritmo\Domain\Relations\Relation;

/**
 * The target never below its reference (plus the margin), unless the reference is on its last
 * units. Raises the floor only.
 */
final class AtLeastRule implements CoherenceRule
{
    public function __construct(private readonly CoherenceContext $context, private readonly TargetAdjuster $adjuster)
    {
    }

    public function evaluate(Relation $relation, DateTimeImmutable $night): Outcome
    {
        $params = $relation->params;
        if (!$params instanceof AtLeastParams) {
            throw new LogicException('An at_least relation needs at_least parameters.');
        }
        $facts = $this->context->facts;
        $reference = $facts->price($params->reference, $night);
        $target = $facts->price($relation->target, $night);
        if ($reference === null || $target === null) {
            return Outcome::NoData;
        }
        $need = $params->margin->applyTo($reference);
        if (!($need > $target + $this->context->settings->triggerAmount)) {
            return Outcome::Satisfied;
        }
        if (!$this->context->chainFree($relation->target, $night)) {
            return Outcome::SkippedSoldOut;
        }
        if ($facts->unitsLeft($params->reference, $night) < $params->referenceMinUnitsLeft) {
            return Outcome::SkippedReferenceLastUnits;
        }
        $reason = new Reason(Reason::AT_LEAST, $relation->id, ['reference' => $params->reference, 'amount' => $reference]);

        return $this->adjuster->apply($relation->target, $night, $need, null, $relation->orderRef, false, $reason);
    }
}
