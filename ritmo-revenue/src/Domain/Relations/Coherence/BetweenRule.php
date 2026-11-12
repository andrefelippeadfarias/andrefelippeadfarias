<?php

declare(strict_types=1);

namespace Ritmo\Domain\Relations\Coherence;

use DateTimeImmutable;
use LogicException;
use Ritmo\Domain\Relations\MemberPolicy;
use Ritmo\Domain\Relations\Params\BetweenMode;
use Ritmo\Domain\Relations\Params\BetweenParams;
use Ritmo\Domain\Relations\Port\BetweenHints;
use Ritmo\Domain\Relations\Relation;

/**
 * The target between its references: from the cheaper one up to a point of the way to the dearer
 * one (mode path), or between two percents of one reference (mode percent_of). The target's own
 * floor beats the band: when it is above the band the ceiling is lifted to it, with a warning.
 */
final class BetweenRule implements CoherenceRule
{
    public function __construct(private readonly CoherenceContext $context, private readonly TargetAdjuster $adjuster)
    {
    }

    public function evaluate(Relation $relation, DateTimeImmutable $night): Outcome
    {
        $params = $relation->params;
        if (!$params instanceof BetweenParams) {
            throw new LogicException('A between relation needs between parameters.');
        }
        $refs = $this->references($params, $night);
        if ($refs === []) {
            return Outcome::EmptySet;
        }
        if (!$this->context->chainFree($relation->target, $night)) {
            return Outcome::SkippedSoldOut;
        }
        $hints = $this->context->hooks->floors->betweenHints($relation, $relation->target, $night);
        [$low, $high, $lo, $hi] = $params->mode === BetweenMode::Path
            ? $this->pathBand($params, $refs, $hints, $relation->target, $night)
            : $this->percentBand($params, $refs[0], $hints);
        $forced = $hints->forcedOrderRef($low);
        $reason = new Reason(Reason::BETWEEN, $relation->id, ['low' => $low, 'high' => $high]);
        $outcome = $this->adjuster->apply($relation->target, $night, $lo, max($lo, $hi), $forced ?? $relation->orderRef, $forced !== null, $reason);
        if ($outcome === Outcome::Applied && $lo > $hi) {
            $this->context->warn(RelationWarning::CEILING_RAISED_TO_FLOOR, $relation, $night, ['floor' => $lo, 'ceiling' => $hi]);
        }

        return $outcome;
    }

    /**
     * Prices of the references that count tonight.
     *
     * @return list<float>
     */
    private function references(BetweenParams $params, DateTimeImmutable $night): array
    {
        $refs = [];
        foreach ($params->referenceRooms() as $room) {
            $price = $this->context->facts->price($room, $night);
            if ($price !== null && ($params->references === MemberPolicy::All || $this->context->chainFree($room, $night))) {
                $refs[] = $price;
            }
        }

        return $refs;
    }

    /**
     * @param list<float> $refs
     *
     * @return array{float, float, float, float} low, high, lower edge, upper edge
     */
    private function pathBand(BetweenParams $params, array $refs, BetweenHints $hints, string $target, DateTimeImmutable $night): array
    {
        $low = min($refs);
        $high = max($refs);
        $lo = max($low + $params->positionMin * ($high - $low), $hints->ownFloor);
        $hi = $low + $params->positionMax * ($high - $low);
        if ($hints->ownTargetCeiling !== null) {
            $hi = min($hi, $hints->ownTargetCeiling);
        }
        if ($hints->holdCurrent) {
            $hi = max($hi, min((float) $this->context->facts->row($target, $night)?->price, $high));
        }

        return [$low, $high, $lo, $hi];
    }

    /**
     * @return array{float, float, float, float}
     */
    private function percentBand(BetweenParams $params, float $reference, BetweenHints $hints): array
    {
        $lo = max($reference * $params->minPercent / 100, $hints->ownFloor);

        return [$reference, $reference, $lo, $reference * $params->maxPercent / 100];
    }
}
