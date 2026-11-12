<?php

declare(strict_types=1);

namespace Ritmo\Domain\Relations\Audit;

use DateTimeImmutable;
use Ritmo\Domain\Relations\Coherence\NightFacts;
use Ritmo\Domain\Relations\Config\RelationSettings;
use Ritmo\Domain\Relations\Params\AtLeastParams;
use Ritmo\Domain\Relations\Params\PremiumParams;
use Ritmo\Domain\Relations\Port\RelationHooks;
use Ritmo\Domain\Relations\PremiumBounds;
use Ritmo\Domain\Relations\Relation;
use Ritmo\Domain\Support\Num;

/**
 * The audit of the top room of a premium relation on one night.
 *
 * Floor: the larger of the room's rule floor (lowered by an owner exception to the safety limit)
 * and the dearest other room times one plus the premium, rounded half to even. On the nights the
 * ladder prices the room, a derived at_least relation adds its requirement (the reference counts
 * even sold out). Above its price band (within the band's horizon) the room gets the band ceiling,
 * never below the floor.
 */
final class PremiumAudit
{
    public function __construct(
        private readonly RelationSettings $settings,
        private readonly NightFacts $calendar,
        private readonly RelationHooks $hooks,
        private readonly DateTimeImmutable $today,
    ) {
    }

    public function check(Relation $relation, PremiumParams $params, ?Relation $derive, DateTimeImmutable $night, float $others): AuditFinding
    {
        $target = $relation->target;
        $premium = $params->premiumFor($this->hooks->calendar->nightType($night));
        $ruleFloor = $this->hooks->floors->ruleFloor($target, $night, $this->today) ?? 0.0;
        $exception = $this->hooks->floors->safetyException($target, $night);
        if ($exception !== null) {
            $ruleFloor = min($ruleFloor, $exception);
        }
        $floor = (float) Num::roundInt(max($ruleFloor, $others * (1 + $premium)));
        $price = (float) $this->calendar->row($target, $night)?->price;
        $tolerance = $this->settings->auditTolerance($this->calendar->room($target));
        $below = $price < $others * (1 + $premium) - $tolerance;
        if ($derive !== null && $derive->params instanceof AtLeastParams && $this->hooks->strategy->governs($target, $night)) {
            $reference = $this->calendar->row($derive->params->reference, $night);
            if ($reference !== null) {
                $need = $derive->params->margin->applyTo($reference->price);
                $floor = max($floor, (float) Num::roundInt($need));
                $below = $below || $price < $need - $tolerance;
            }
        }
        $ceiling = null;
        $band = PremiumBounds::bandOf($this->hooks->bands, $target, $night, $this->today);
        if ($band !== null && $price > $band->high + $tolerance) {
            $ceiling = max($band->high, $floor);
            $floor = max($floor, min($band->low, (float) Num::roundInt($price)));
        }
        $out = $below || $ceiling !== null;

        return new AuditFinding($relation->id, $relation->type, $target, $night, $price, $floor, $ceiling, $others, 0.0, null, $out, $price);
    }
}
