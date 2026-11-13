<?php

declare(strict_types=1);

namespace Ritmo\Application\Run;

use Ritmo\Domain\Config\RuleSet;
use Ritmo\Domain\Plan\Assembly\AssembledPlan;
use Ritmo\Domain\Plan\Assembly\PlanAssembler;
use Ritmo\Domain\Plan\Assembly\RunInputs;
use Ritmo\Domain\Plan\Assembly\RunWiring;
use Ritmo\Domain\Plan\FreshCalendar;
use Ritmo\Domain\Plan\Plan;
use Ritmo\Domain\Plan\ValidatedPlan;
use Ritmo\Domain\Pricing\Cadence\StepProposal;
use Ritmo\Domain\Support\Clock;

/**
 * A plan built again for writing: the inputs it was built from, the assembly, and the way to
 * validate a plan cut down from it with the same context.
 */
final class RebuiltPlan
{
    public function __construct(
        public readonly RunInputs $inputs,
        private readonly PlanAssembler $assembler,
        public readonly AssembledPlan $assembled,
        private readonly RuleSet $rules,
        private readonly Clock $clock,
    ) {
    }

    /**
     * The plan as the validator let it through (without what it refused), or null.
     */
    public function validated(): ?ValidatedPlan
    {
        return $this->assembled->finalValidation?->plan;
    }

    /**
     * A plan cut down from this one, validated again; null when it no longer passes.
     */
    public function revalidate(Plan $plan): ?ValidatedPlan
    {
        $result = $this->assembler->revalidate($this->inputs, $plan, $this->assembled->relations);

        return $result->isValid() ? $result->plan : null;
    }

    public function calendar(): FreshCalendar
    {
        return (new RunWiring($this->rules, $this->clock, $this->inputs))->freshCalendar();
    }

    /**
     * The pace and ladder steps of the items of a plan, for the step ledger once they are stored.
     *
     * @return list<StepProposal>
     */
    public static function proposals(Plan $plan): array
    {
        $out = [];
        foreach ($plan->items as $item) {
            $step = $item->reasonParams['step'] ?? null;
            if (is_array($step)) {
                $out[] = StepProposal::fromArray($step);
            }
        }

        return $out;
    }
}
