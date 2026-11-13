<?php

declare(strict_types=1);

namespace Ritmo\Application\ShadowRun;

use Ritmo\Domain\Plan\Assembly\NightLine;
use Ritmo\Application\Run\StayWriteStep;
use Ritmo\Domain\Plan\Assembly\PlanAssembler;
use Ritmo\Domain\Support\Clock;

/**
 * Assembles the plan of the run (Domain\Plan\Assembly\PlanAssembler): every room night priced
 * (pace, owner orders, sold-out protection, sales ladder where a strategy acts), the relations
 * between rooms, the delete-first plan for the ceilings to free, the no-effect filter, the
 * validator with the run's context, the payload and its slices; then the stay writes
 * (Run\StayWriteStep: PMS changes and pricing stay items, sealed with the plan). A room night without a market
 * anchor is "no_market_data"; one the PMS did not report, or without a price, is left out.
 */
final class DecideStage implements Stage
{
    public const NAME = 'decide';

    public const NO_MARKET_DATA = 'no_market_data';

    public function __construct(
        private readonly RunInputsReader $inputs,
        private readonly Clock $clock,
        private readonly string $pmsName,
        private readonly StayWriteStep $stay,
    ) {
    }

    public function name(): string
    {
        return self::NAME;
    }

    public function run(RunContext $context): StageResult
    {
        $inputs = $this->inputs->read($context, $this->clock->now());
        $assembler = new PlanAssembler($context->rules, $this->clock, $this->pmsName);
        $stay = $this->stay->apply($context, $inputs, $assembler, $assembler->assemble($inputs));
        $run = $stay->assembled;
        $state = AssemblyState::of($run, $this->inputs->priceTraces(), $context->rooms) + ['pms' => PmsState::of($stay)];
        $byReason = $state['lines'];
        $noAnchor = ($byReason[NightLine::NO_MARKET_ANCHOR] ?? 0) + ($byReason[NightLine::SOLD_OUT_NO_ANCHOR] ?? 0);

        return new StageResult(
            [
                'nights' => array_sum($byReason),
                'steps' => count($state['items']),
                'deletions' => count($state['deletions']),
                'by_reason' => $byReason,
                self::NO_MARKET_DATA => $noAnchor,
            ] + $stay->summary(),
            $state,
        );
    }
}
