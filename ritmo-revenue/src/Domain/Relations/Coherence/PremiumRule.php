<?php

declare(strict_types=1);

namespace Ritmo\Domain\Relations\Coherence;

use DateTimeImmutable;
use LogicException;
use Ritmo\Domain\Relations\MemberPolicy;
use Ritmo\Domain\Relations\Params\PremiumParams;
use Ritmo\Domain\Relations\PremiumBounds;
use Ritmo\Domain\Relations\Relation;

/**
 * The target above the dearest room of its set by the premium of the night type (plus a small
 * amount, so that it is strictly above). When the target has a price band tonight, its ceiling is
 * the band ceiling, lifted to the floor when the relation asks for more.
 */
final class PremiumRule implements CoherenceRule
{
    public function __construct(private readonly CoherenceContext $context, private readonly TargetAdjuster $adjuster)
    {
    }

    public function evaluate(Relation $relation, DateTimeImmutable $night): Outcome
    {
        $params = $relation->params;
        if (!$params instanceof PremiumParams) {
            throw new LogicException('A premium_over_max relation needs premium parameters.');
        }
        $others = [];
        foreach ($this->context->graph->members($relation) as $room) {
            $price = $this->context->facts->price($room, $night);
            if ($price !== null && ($params->members === MemberPolicy::All || $this->context->chainFree($room, $night))) {
                $others[] = $price;
            }
        }
        if ($others === []) {
            return Outcome::EmptySet;
        }
        if (!$this->context->chainFree($relation->target, $night)) {
            return Outcome::SkippedSoldOut;
        }
        $premium = $params->premiumFor($this->context->nightType($night));
        $floor = max($others) * (1 + $premium) + $params->epsilonAmount;
        $band = PremiumBounds::bandOf($this->context->hooks->bands, $relation->target, $night, $this->context->today);
        $ceiling = $band !== null && $band->high > 0.0 ? max($band->high, $floor) : null;
        $reason = new Reason(Reason::PREMIUM_OVER_MAX, $relation->id, ['premium' => $premium, 'amount' => max($others)]);
        $outcome = $this->adjuster->apply($relation->target, $night, $floor, $ceiling, $relation->orderRef, false, $reason);
        if ($outcome === Outcome::Applied && $band !== null && $floor > $band->high) {
            $this->context->warn(RelationWarning::FLOOR_OVER_BAND_CEILING, $relation, $night, ['floor' => $floor, 'band_ceiling' => $band->high]);
        }

        return $outcome;
    }
}
