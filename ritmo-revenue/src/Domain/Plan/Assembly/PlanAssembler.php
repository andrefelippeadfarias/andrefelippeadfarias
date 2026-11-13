<?php

declare(strict_types=1);

namespace Ritmo\Domain\Plan\Assembly;

use Ritmo\Domain\Calendar\Stay\StayHook;
use Ritmo\Domain\Config\RuleSet;
use Ritmo\Domain\Plan\IssueSubject;
use Ritmo\Domain\Plan\NightState;
use Ritmo\Domain\Plan\Payload\PayloadBuilder;
use Ritmo\Domain\Plan\Payload\Slicer;
use Ritmo\Domain\Plan\Plan;
use Ritmo\Domain\Plan\PlanDeletion;
use Ritmo\Domain\Plan\PlanIssue;
use Ritmo\Domain\Plan\PlanItem;
use Ritmo\Domain\Plan\ValidationContext;
use Ritmo\Domain\Plan\ValidationResult;
use Ritmo\Domain\Plan\Validator;
use Ritmo\Domain\Pricing\Cadence\NoEffectResult;
use Ritmo\Domain\Relations\Coherence\CoherenceContext;
use Ritmo\Domain\Relations\Coherence\CoherencePass;
use Ritmo\Domain\Relations\Coherence\NightSet;
use Ritmo\Domain\Relations\Coherence\PlanDraft;
use Ritmo\Domain\Relations\Coherence\RelationReport;
use Ritmo\Domain\Stay\Write\Expiry\ExpiryRewrite;
use Ritmo\Domain\Stay\Write\Expiry\ExpiryWindow;
use Ritmo\Domain\Stay\Write\PmsChangeEntry;
use Ritmo\Domain\Support\Clock;

/**
 * Builds the plan of one property for one run, end to end:
 *
 *   price every room night (gates, sales ladder, sold-out protection, pace step with its terms)
 *   -> relations between rooms (coherence) -> listing maximum -> step blocks follow the final floor
 *   -> delete-first plan for the ceilings to free (with the stay rules) -> no-effect filter
 *   -> delete + rewrite on the nights whose stored rule has a lead-time expiry (W36)
 *   -> Validator -> (again without what it refused) -> PayloadBuilder -> Slicer.
 *
 * Precedence: safety (validator, today never rises) > owner orders and floors > ladder and pace >
 * relations. A light run passes the room nights it reaches in RunInputs::$lightFilter; the other
 * nights still feed the relations. Nothing is written anywhere.
 *
 * Reference: pace.py plano (pace._plano, ajustar_tetos, sem_efeito), validar_plano and fatiar_payload.
 */
final class PlanAssembler
{
    public function __construct(
        private readonly RuleSet $rules,
        private readonly Clock $clock,
        private readonly string $pmsName,
    ) {
    }

    public function assemble(RunInputs $inputs): AssembledPlan
    {
        $wiring = new RunWiring($this->rules, $this->clock, $inputs);
        $facts = $wiring->pricingFacts();
        $pricing = $wiring->nightPricing($facts);
        $pricing->run();
        $report = new RelationReport();
        $work = $this->relations($wiring, $pricing, $facts->decidedPrices(), $report);
        foreach ($work as $item) {
            $item->syncStep($this->rules->tolerance());
        }
        $deletions = (new CeilingRelease(new StayHook($wiring->stay), $this->rules->noEffect()->moneySlack))->apply($work, $inputs, $wiring->occupancy());
        $kept = $this->withoutEffectless($work, $inputs, $deletions);
        self::switchesSatisfied([...$kept->noEffect, ...$kept->roundingOnly], $report);
        [$items, $deletions] = ExpiryRewrite::apply($kept->kept, $deletions, $inputs->overrides, self::expiries($inputs), $inputs->rooms);
        $plan = new Plan($inputs->property->id, $inputs->today, $this->clock->now(), $items, $deletions);

        $validator = new Validator($this->rules, $this->clock, $this->rules->plan());
        $context = $wiring->validationContext($report);
        $rooms = array_values($inputs->rooms);
        $validation = $validator->validate($plan, $inputs->property, $rooms, $context);
        $final = $validation->isValid() ? $validation : $this->withoutRefused($validator, $validation, $plan, $inputs, $wiring->validationContext($report, true));

        return $this->finish($inputs, $plan, $validation, $final, $pricing->lines(), $kept->noEffect, $kept->roundingOnly, $report);
    }

    /**
     * The plan of an assembly with other items and these PMS changes (the stay writes of the run,
     * Stay\Write\StayWritePlanner, merged into the price items): validated with the same context,
     * again without what the validator refused, with its payload and slices. The deletions, the
     * grid lines and the relations report stay those of the assembly.
     *
     * @param list<PlanItem>       $items
     * @param list<PmsChangeEntry> $pmsChanges
     */
    public function reassemble(RunInputs $inputs, AssembledPlan $assembled, array $items, array $pmsChanges): AssembledPlan
    {
        $wiring = new RunWiring($this->rules, $this->clock, $inputs);
        $first = $assembled->plan;
        $plan = new Plan($first->propertyId, $first->today, $first->createdAt, $items, $first->deletions, $first->listingChanges, $pmsChanges);
        $validator = new Validator($this->rules, $this->clock, $this->rules->plan());
        $validation = $validator->validate($plan, $inputs->property, array_values($inputs->rooms), $wiring->validationContext($assembled->relations));
        $final = $validation->isValid() ? $validation : $this->withoutRefused($validator, $validation, $plan, $inputs, $wiring->validationContext($assembled->relations, true));

        return $this->finish($inputs, $plan, $validation, $final, $assembled->lines, $assembled->noEffect, $assembled->roundingOnly, $assembled->relations);
    }

    /**
     * Validates again a plan cut down from the one this assembly built for the same inputs (the
     * entries a person approved, the plan without the cuts a cancellation wave holds, the repair
     * of a broken group), with the same context and the relations report of the assembly.
     */
    public function revalidate(RunInputs $inputs, Plan $plan, RelationReport $report): ValidationResult
    {
        $wiring = new RunWiring($this->rules, $this->clock, $inputs);

        return (new Validator($this->rules, $this->clock, $this->rules->plan()))->validate($plan, $inputs->property, array_values($inputs->rooms), $wiring->validationContext($report, true));
    }

    /**
     * The relations stage over the items of the pricing stage, then the listing maximum: items
     * changed in place, created or removed, as the relations decide.
     *
     * @param array<string, float> $decided room-night key => effective price of an item so far
     *
     * @return list<WorkItem>
     */
    private function relations(RunWiring $wiring, NightPricing $pricing, array $decided, RelationReport $report): array
    {
        $byDraft = [];
        $drafts = [];
        foreach ($pricing->items() as $item) {
            $draft = $item->toDraft();
            $byDraft[spl_object_id($draft)] = $item;
            $drafts[] = $draft;
        }
        $plan = new PlanDraft($drafts);
        $facts = $wiring->facts($decided, $pricing->strategyDecided());
        $settings = $wiring->relationsConfig->settings;
        $context = new CoherenceContext($wiring->graph, $settings, $facts, $wiring->hooks, $plan, $report, $wiring->inputs->today);
        $horizon = min($wiring->inputs->days, $settings->scanHorizonDays);
        (new CoherencePass($context))->run(NightSet::of($wiring->graph, $facts, $wiring->inputs->today, $horizon));
        $wiring->caps->apply($plan);

        $out = [];
        foreach ($plan->items() as $draft) {
            $item = $byDraft[spl_object_id($draft)] ?? null;
            if ($item === null) {
                $item = WorkItem::fromDraft($draft, NightState::from($wiring->calendar->row($draft->room, $draft->night), $wiring->inputs->override($draft->room, $draft->night)));
            } else {
                $item->absorb($draft);
            }
            $out[] = $item;
        }

        return $out;
    }

    /**
     * @param list<WorkItem>                         $work
     * @param list<PlanDeletion> $deletions
     */
    private function withoutEffectless(array $work, RunInputs $inputs, array $deletions): NoEffectResult
    {
        $live = $overrides = [];
        foreach ($inputs->calendar as $code => $nights) {
            foreach ($nights as $night) {
                $key = PlanItem::keyOf((string) $code, $night->date);
                $live[$key] = $night;
                $override = $inputs->override((string) $code, $night->date);
                if ($override !== null) {
                    $overrides[$key] = $override;
                }
            }
        }
        $deleteFirst = [];
        foreach ($deletions as $deletion) {
            $deleteFirst[$deletion->key()] = true;
        }

        return $this->rules->noEffect()->filter()->filter(array_map(static fn (WorkItem $w): PlanItem => $w->toPlanItem(), $work), $inputs->rooms, $live, $overrides, $deleteFirst);
    }

    /**
     * The stored date rules with a lead-time expiry (W36): a write on their night is a delete +
     * rewrite group, never a merge.
     *
     * @return array<string, array<string, ExpiryWindow>> room => night => expiry
     */
    private static function expiries(RunInputs $inputs): array
    {
        $out = [];
        foreach ($inputs->overrides as $room => $nights) {
            foreach ($nights as $night => $override) {
                $expiry = $override->expiry();
                if ($expiry !== null) {
                    $out[(string) $room][(string) $night] = $expiry;
                }
            }
        }

        return $out;
    }

    /**
     * The plan validated again without the entries the validator refused, as the run would write
     * it; null when even that does not pass. A deletion and the item that writes its night again
     * are one group: when either is refused, both go.
     */
    private function withoutRefused(Validator $validator, ValidationResult $first, Plan $plan, RunInputs $inputs, ValidationContext $context): ?ValidationResult
    {
        $refused = [];
        foreach ($first->rejected(IssueSubject::Item) as $index) {
            $refused[$plan->items[$index]->key()] = true;
        }
        foreach ($first->rejected(IssueSubject::Deletion) as $index) {
            $refused[$plan->deletions[$index]->key()] = true;
        }
        $items = array_keys(array_filter($plan->items, static fn (PlanItem $item): bool => isset($refused[$item->key()])));
        $deletions = array_keys(array_filter($plan->deletions, static fn (PlanDeletion $deletion): bool => isset($refused[$deletion->key()])));
        $effectless = array_filter($first->rejected(IssueSubject::Item), static fn (int $index): bool => self::onlyWithoutEffect($first, $index));
        self::switchesSatisfied(array_map(static fn (int $index): PlanItem => $plan->items[$index], $effectless), $context->relationReport);
        $rest = $plan->without($items, $deletions, $first->rejected(IssueSubject::Listing), $first->rejected(IssueSubject::Pms));
        $again = $validator->validate($rest, $inputs->property, array_values($inputs->rooms), $context);

        return $again->isValid() ? $again : null;
    }

    /**
     * An occupancy switch whose item is dropped for having no effect is satisfied without an item:
     * the night is already at the switched price, and the discount band check accepts it so
     * (the reference refuses the band there, see docs/ENGINE.md 1.8).
     *
     * @param array<PlanItem> $dropped
     */
    private static function switchesSatisfied(array $dropped, ?RelationReport $report): void
    {
        foreach ($dropped as $item) {
            if ($report !== null && in_array(WorkItem::RELATION_OCCUPANCY_SWITCH, $item->reasonParams['relation_codes'] ?? [], true)) {
                $report->switchItemDropped($item->roomCode, $item->date);
            }
        }
    }

    /**
     * The validator refused the item only because it changes nothing.
     */
    private static function onlyWithoutEffect(ValidationResult $result, int $index): bool
    {
        foreach ($result->errors as $issue) {
            if ($issue->subject === IssueSubject::Item && $issue->itemIndex === $index && $issue->code !== PlanIssue::NO_EFFECT) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param list<NightLine> $lines
     * @param list<PlanItem>  $noEffect
     * @param list<PlanItem>  $roundingOnly
     */
    private function finish(RunInputs $inputs, Plan $plan, ValidationResult $validation, ?ValidationResult $final, array $lines, array $noEffect, array $roundingOnly, RelationReport $report): AssembledPlan
    {
        $payload = null;
        $slices = [];
        if ($final?->plan !== null) {
            $payload = (new PayloadBuilder($this->pmsName, $this->rules->plan()))->build($final->plan, $inputs->property, array_values($inputs->rooms));
            $slices = (new Slicer($this->rules->plan()->sliceMaxDates))->slice($payload, $inputs->today);
        }

        return new AssembledPlan($plan, $validation, $final, $payload, $slices, $lines, $noEffect, $roundingOnly, $report);
    }
}
