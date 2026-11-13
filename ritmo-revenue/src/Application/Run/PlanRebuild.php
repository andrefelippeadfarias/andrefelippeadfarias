<?php

declare(strict_types=1);

namespace Ritmo\Application\Run;

use Ritmo\Application\ShadowRun\RunContext;
use Ritmo\Application\ShadowRun\RunInputsReader;
use Ritmo\Domain\Plan\Assembly\PlanAssembler;
use Ritmo\Domain\Support\Clock;

/**
 * Builds the plan of a run again, in the request that writes it, from what the run collected: a
 * validated plan lives only in the process that validated it (the write gate takes nothing else),
 * so the write stage never takes a plan from storage. The same reads give the same plan the
 * decision stages showed; the gate then checks that those reads are still fresh.
 */
final class PlanRebuild
{
    public function __construct(
        private readonly RunInputsReader $inputs,
        private readonly Clock $clock,
        private readonly string $pmsName,
        private readonly StayWriteStep $stay,
    ) {
    }

    public function rebuild(RunContext $context): RebuiltPlan
    {
        $inputs = $this->inputs->read($context, $this->clock->now());
        $assembler = new PlanAssembler($context->rules, $this->clock, $this->pmsName);

        $stay = $this->stay->apply($context, $inputs, $assembler, $assembler->assemble($inputs));

        return new RebuiltPlan($inputs, $assembler, $stay->assembled, $context->rules, $this->clock, $stay);
    }
}
