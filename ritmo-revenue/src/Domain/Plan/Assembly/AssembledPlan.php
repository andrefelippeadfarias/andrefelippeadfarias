<?php

declare(strict_types=1);

namespace Ritmo\Domain\Plan\Assembly;

use Ritmo\Domain\Plan\Payload\Payload;
use Ritmo\Domain\Plan\Payload\Slice;
use Ritmo\Domain\Plan\Plan;
use Ritmo\Domain\Plan\PlanItem;
use Ritmo\Domain\Plan\ValidationResult;
use Ritmo\Domain\Relations\Coherence\RelationReport;

/**
 * Everything one assembly produced for a property: the plan as built, its validation, the plan
 * that passes (without the entries the validator refused), the payload and its slices ready for
 * the writer of a later milestone, the nights grid and what was dropped on the way.
 *
 * Nothing here was sent anywhere: the payload and the slices are data.
 */
final class AssembledPlan
{
    /**
     * @param list<NightLine> $lines       every room night of the horizon with what the engine did
     * @param list<PlanItem>  $noEffect    items dropped because everything they set is in force
     * @param list<PlanItem>  $roundingOnly items dropped because they only moved the rounding
     * @param list<Slice>     $slices      the payload cut in calls, in writing order
     */
    public function __construct(
        public readonly Plan $plan,
        public readonly ValidationResult $validation,
        public readonly ?ValidationResult $finalValidation,
        public readonly ?Payload $payload,
        public readonly array $slices,
        public readonly array $lines,
        public readonly array $noEffect,
        public readonly array $roundingOnly,
        public readonly RelationReport $relations,
    ) {
    }

    /**
     * The plan passed the validator as built, with nothing refused.
     */
    public function isValid(): bool
    {
        return $this->validation->isValid();
    }
}
