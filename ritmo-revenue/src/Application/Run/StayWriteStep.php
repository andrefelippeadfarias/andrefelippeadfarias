<?php

declare(strict_types=1);

namespace Ritmo\Application\Run;

use Ritmo\Application\ShadowRun\RunContext;
use Ritmo\Application\Write\Calendar\PmsWriteBatch;
use Ritmo\Application\Write\Calendar\StayWriteRound;
use Ritmo\Domain\Calendar\Stay\InventoryAudit;
use Ritmo\Domain\Calendar\Stay\PricingStayNight;
use Ritmo\Domain\Calendar\Stay\PricingStayPlan;
use Ritmo\Domain\Calendar\Stay\StayBatch;
use Ritmo\Domain\Calendar\Stay\StaySyncCapability;
use Ritmo\Domain\Operations\RunKind;
use Ritmo\Domain\Plan\Assembly\AssembledPlan;
use Ritmo\Domain\Plan\Assembly\PlanAssembler;
use Ritmo\Domain\Plan\Assembly\RunInputs;
use Ritmo\Domain\Plan\Assembly\RunWiring;
use Ritmo\Domain\Plan\PlanItem;
use Ritmo\Domain\Stay\Write\PmsCheckContext;
use Ritmo\Domain\Stay\Write\PushedPmsValues;
use Ritmo\Domain\Stay\Write\RevertLedger;
use Ritmo\Domain\Stay\Write\StayPairing;
use Ritmo\Domain\Stay\Write\StayWriteInputs;
use Ritmo\Domain\Stay\Write\StayWritePlanner;
use Ritmo\Domain\Support\Clock;
use Ritmo\Domain\Support\Days;
use Ritmo\Infrastructure\Db\Repository\InventorySnapshotRepository;

/**
 * The stay writes of a full run (stay spec 3.4-3.6), after the plan assembly: the PMS changes the
 * stay rules ask for (none on a frozen night), the pricing stay items merged into the price items,
 * every pushed PMS change paired; the merged plan validated again, the PMS changes checked against
 * the pricing items the validator accepted, and the accepted ones sealed with the plan, so the gate
 * writes them and nothing else. Off (stay.write.enabled false) and in light runs the assembly is
 * kept as it is.
 */
final class StayWriteStep
{
    public function __construct(
        private readonly InventorySnapshotRepository $snapshots,
        private readonly StaySyncCapability $capability,
        private readonly Clock $clock,
    ) {
    }

    public function apply(RunContext $context, RunInputs $inputs, PlanAssembler $assembler, AssembledPlan $assembled): StayAssembly
    {
        $config = $context->rules->stayWrite();
        if (!$config->enabled || $context->kind === RunKind::Light->value) {
            return new StayAssembly($assembled, null);
        }
        $wiring = new RunWiring($context->rules, $this->clock, $inputs);
        $stayInputs = $this->inputs($context, $inputs, $wiring, $assembled->plan->items);
        $plan = $this->planner($context, $wiring)->plan($stayInputs);
        $merged = $assembler->reassemble($inputs, $assembled, $plan->items, []);
        $batch = (new StayWriteRound())->batch($plan, $this->checkContext($context, $inputs, $stayInputs, $merged), $inputs->rooms);
        if ($batch->check->accepted === []) {
            return new StayAssembly($merged, $batch);
        }

        return new StayAssembly($assembler->reassemble($inputs, $merged, $plan->items, $batch->check->accepted), $batch);
    }

    private function planner(RunContext $context, RunWiring $wiring): StayWritePlanner
    {
        $stay = $context->rules->stay();
        $propertyOfRoom = array_map(static fn ($room): string => $room->propertyId, $wiring->inputs->rooms);

        return new StayWritePlanner(
            $context->rules->stayWrite(),
            new InventoryAudit($wiring->stay, $this->capability),
            new StayBatch($stay->batch, $propertyOfRoom),
            new PricingStayPlan($wiring->stay),
            new StayPairing(new PushedPmsValues($stay->pricingSync, $this->capability)),
        );
    }

    /**
     * @param list<PlanItem> $priceItems
     */
    private function inputs(RunContext $context, RunInputs $inputs, RunWiring $wiring, array $priceItems): StayWriteInputs
    {
        $today = $context->today;
        $to = min(Days::add($today, $context->rules->stayWrite()->horizonDays - 1), $context->horizon->last());
        $inventory = $calendar = $effective = $frozen = [];
        foreach ($inputs->rooms as $code => $room) {
            $inventory[$code] = $this->snapshots->latest($room->code, $today, $to);
            foreach ($inputs->calendar[$code] ?? [] as $key => $night) {
                $calendar[$code][(string) $key] = PricingStayNight::fromPriceNight($night);
                if ($night->minStay !== null) {
                    $effective[$code][(string) $key] = $night->minStay;
                }
            }
            for ($night = $today; $night <= $to; $night = Days::add($night, 1)) {
                if ($inputs->orders->manualFreeze($room->code, $night) !== null) {
                    $frozen[$room->code . '|' . Days::key($night)] = true;
                }
            }
        }

        return new StayWriteInputs(
            $today,
            $today,
            $to,
            $context->capturedAt(),
            $inventory,
            $calendar,
            $wiring->occupancy(),
            $effective,
            $inputs->overrides,
            $priceItems,
            $frozen,
            Days::add($today, $context->rules->write()->horizonDays - 1),
        );
    }

    private function checkContext(RunContext $context, RunInputs $inputs, StayWriteInputs $stay, AssembledPlan $merged): PmsCheckContext
    {
        $accepted = [];
        foreach ($merged->finalValidation?->plan?->plan->items ?? [] as $item) {
            $accepted[$item->key()] = true;
        }
        $plan = $context->rules->plan();

        return new PmsCheckContext(
            $this->clock->today($context->property->timezone),
            $this->clock->now(),
            $inputs->rooms,
            $stay->inventory,
            $stay->frozen,
            [],
            $accepted,
            [],
            new RevertLedger(),
            $plan->maxMinStay,
            (int) ceil($plan->calendarMaxAgeHours),
            $context->rules->stayWrite(),
        );
    }
}
