<?php

declare(strict_types=1);

namespace Ritmo\Application\Run;

use Ritmo\Application\Alerts\AlertService;
use Ritmo\Application\Approval\ApprovalRefused;
use Ritmo\Application\Approval\ApprovalService;
use Ritmo\Application\Approval\CapabilityCheck;
use Ritmo\Application\Approval\ApprovedSubset;
use Ritmo\Application\Approval\WaveHoldService;
use Ritmo\Application\Mode\ModeService;
use Ritmo\Application\ShadowRun\RunContext;
use Ritmo\Application\ShadowRun\Stage;
use Ritmo\Application\ShadowRun\StageResult;
use Ritmo\Application\Write\Gate\HumanApproval;
use Ritmo\Application\Write\Gate\PlanHash;
use Ritmo\Application\Write\Gate\WriteAuthorization;
use Ritmo\Application\Write\Gate\WriteRefused;
use Ritmo\Application\Write\Resume\BrokenGroup;
use Ritmo\Domain\Alerts\AlertCode;
use Ritmo\Domain\Alerts\AlertDraft;
use Ritmo\Domain\Model\PropertyMode;
use Ritmo\Domain\Operations\RunKind;
use Ritmo\Domain\Plan\ValidatedPlan;
use Ritmo\Domain\Support\Clock;
use Ritmo\Domain\Write\Hold\CancellationHold;
use Ritmo\Domain\Write\Hold\HeldEntry;
use Ritmo\Infrastructure\Db\Repository\OperationEventRepository;
use Ritmo\Infrastructure\Db\Repository\PlanApprovalRepository;
use Ritmo\Infrastructure\Db\Repository\PropertyRepository;
use Ritmo\Infrastructure\Db\Repository\WaveHoldRepository;

/**
 * What the run does with its validated plan, by the mode it runs in:
 *
 * - shadow (and any run under the emergency switch): nothing; the plan was stored by the shadow
 *   stage and zero writes leave;
 * - approval: the plan (without the cuts a cancellation wave holds) waits for a person; nothing is
 *   written until a person approves it, and then the approved write run builds the plan again
 *   from fresh reads, keeps only the approved entries with the same fields, validates them again
 *   and writes them with a token issued for exactly that subset;
 * - autonomous: the plan goes to the write gate with the property's autonomous authorization.
 *
 * A group left open by an earlier write (found by the watch stage) is repaired first and alone:
 * at once when autonomous, and proposed first for approval in approval mode. The write of a run
 * happens once (WriteRunner::guardOnce). The listing values (minimum, base, maximum) never leave
 * in an autonomous run.
 */
final class WriteStage implements Stage
{
    public const NAME = 'write';

    public function __construct(
        private readonly PlanRebuild $rebuild,
        private readonly WriteRunner $runner,
        private readonly PlanApprovalRepository $requests,
        private readonly WaveHoldRepository $holds,
        private readonly CapabilityCheck $capabilities,
        private readonly ModeService $modes,
        private readonly PropertyRepository $properties,
        private readonly AlertService $alerts,
        private readonly OperationEventRepository $events,
        private readonly WriteReport $report,
        private readonly Clock $clock,
    ) {
    }

    public function name(): string
    {
        return self::NAME;
    }

    public function run(RunContext $context): StageResult
    {
        if ($context->mode === PropertyMode::Shadow) {
            return new StageResult(['mode' => PropertyMode::Shadow->value, 'writes_sent' => 0], []);
        }
        if ($context->mode === PropertyMode::Approval && $context->kind !== RunKind::ApprovedWrite->value) {
            return $this->propose($context);
        }

        return $this->write($context);
    }

    private function propose(RunContext $context): StageResult
    {
        $rebuilt = $this->rebuild->rebuild($context);
        $broken = self::broken($context);
        if ($broken !== []) {
            return $this->proposeRepair($context, $this->runner->repairPlan($context, $rebuilt, $broken));
        }
        [$plan, $held] = $this->held($context, $rebuilt, $rebuilt->validated());
        if ($plan === null || $plan->plan->isEmpty()) {
            return new StageResult(['mode' => PropertyMode::Approval->value, 'repair' => false, 'proposed' => false, 'held' => count($held), 'writes_sent' => 0], ['write' => ['held' => $held]]);
        }
        $ids = $this->approvals($context)->propose($plan, $context->runId, $context->capturedAt() ?? $this->clock->now(), $context->rulesetVersion);
        $this->alerts->raise(new AlertDraft(
            AlertCode::ApprovalPending,
            $context->property->id,
            ['plan'],
            sprintf('A plan of %d room nights waits for approval.', count($plan->plan->items) + count($plan->plan->deletions)),
            ['approval_ids' => $ids],
        ), $context->runId);

        return new StageResult(
            ['mode' => PropertyMode::Approval->value, 'repair' => false, 'proposed' => true, 'approval_ids' => $ids, 'entries' => count($plan->plan->items) + count($plan->plan->deletions), 'held' => count($held), 'writes_sent' => 0],
            ['write' => ['held' => $held]],
        );
    }

    /**
     * In approval mode a group left open is repaired only once a person approves its repair: it
     * is proposed first and alone, before any other plan.
     */
    private function proposeRepair(RunContext $context, ?ValidatedPlan $repair): StageResult
    {
        $base = ['mode' => PropertyMode::Approval->value, 'repair' => true, 'held' => 0, 'writes_sent' => 0];
        if ($repair === null || $repair->plan->isEmpty()) {
            return new StageResult($base + ['proposed' => false], []);
        }
        $ids = $this->approvals($context)->propose($repair, $context->runId, $context->capturedAt() ?? $this->clock->now(), $context->rulesetVersion);
        $this->alerts->raise(new AlertDraft(
            AlertCode::ApprovalPending,
            $context->property->id,
            ['plan'],
            sprintf('The repair of %d room nights left without their date rule waits for approval.', count($repair->plan->items)),
            ['approval_ids' => $ids, 'repair' => true],
        ), $context->runId);

        return new StageResult($base + ['proposed' => true, 'approval_ids' => $ids, 'entries' => count($repair->plan->items)], []);
    }

    private function write(RunContext $context): StageResult
    {
        $this->runner->guardOnce($context);
        $rebuilt = $this->rebuild->rebuild($context);
        [$approval, $plan, $repair] = $this->planToWrite($context, $rebuilt);
        [$plan, $held] = $this->held($context, $rebuilt, $plan);
        $base = ['mode' => $context->mode->value, 'repair' => $repair, 'held' => count($held)];
        if ($plan === null || $plan->plan->isEmpty()) {
            return new StageResult($base + ['writes_sent' => 0, 'nothing_to_write' => true], ['write' => ['held' => $held]]);
        }
        $auth = $approval ?? $this->modes->autonomousAuthorization($context->property);
        if ($auth === null) {
            return new StageResult($base + ['writes_sent' => 0, 'refused' => WriteRefused::MODE_NOT_AUTONOMOUS], ['write' => ['held' => $held]]);
        }

        return $this->send($context, $rebuilt, $plan, $auth, $held, $base);
    }

    /**
     * @param list<array<string, mixed>> $held
     * @param array<string, mixed>       $base
     */
    private function send(RunContext $context, RebuiltPlan $rebuilt, ValidatedPlan $plan, WriteAuthorization $auth, array $held, array $base): StageResult
    {
        $propertyId = $context->property->id;
        $mode = $context->property->mode;
        $stillAllowed = fn (): bool => $this->properties->find($propertyId)?->mode === $mode;
        try {
            $outcome = $this->runner->write($context, $rebuilt, $plan, $auth, $stillAllowed, WriteRunner::staleListings($context, $plan));
        } catch (WriteRefused $refused) {
            return new StageResult($base + ['writes_sent' => 0, 'refused' => $refused->reason], ['write' => ['held' => $held]]);
        }
        $summary = $this->report->summarize($context, $outcome, $auth->actor());
        if ($auth instanceof HumanApproval) {
            $this->approvals($context)->markWritten((int) $auth->id, $context->runId);
            $this->events->record($propertyId, OperationEventRepository::APPROVAL, 'approved', 'written', sprintf('Request %s written.', $auth->id), $auth->actor(), $this->clock->now(), $context->runId);
        } else {
            $this->modes->afterWrite($context->property, $summary['failed'], $context->rules->modes());
        }

        return new StageResult($base + $summary, ['write' => [
            'actor' => $auth->actor(),
            'bases' => $plan->bases,
            'proposals' => array_map(static fn ($p): array => $p->toArray(), RebuiltPlan::proposals($plan->plan)),
            'held' => $held,
            'sync_now' => $summary['sync_now'],
        ]]);
    }

    /**
     * What this run writes and under which approval: the repair of the groups left open first and
     * alone (autonomous, or covered by the approval being written: the repair a person approved);
     * else the approved entries of the fresh plan, or the whole fresh plan when autonomous. An
     * approval that does not cover the repair is still written: the repair is proposed again.
     *
     * @return array{0: HumanApproval|null, 1: ValidatedPlan|null, 2: bool} approval, plan, whether it is the repair
     */
    private function planToWrite(RunContext $context, RebuiltPlan $rebuilt): array
    {
        $broken = self::broken($context);
        $repair = $broken === [] ? null : $this->runner->repairPlan($context, $rebuilt, $broken);
        if ($context->kind !== RunKind::ApprovedWrite->value) {
            return $repair !== null ? [null, $repair, true] : [null, $rebuilt->validated(), false];
        }
        if ($repair !== null) {
            [$approval, $plan] = $this->approved($context, $rebuilt, $repair);
            if ($plan !== null) {
                return [$approval, $plan, true];
            }
        }
        [$approval, $plan] = $this->approved($context, $rebuilt, $rebuilt->validated());

        return [$approval, $plan, false];
    }

    /**
     * The groups the watch stage found open.
     *
     * @return list<BrokenGroup>
     */
    private static function broken(RunContext $context): array
    {
        $groups = is_array($context->state['broken_groups'] ?? null) ? $context->state['broken_groups'] : [];

        return array_values(array_map(static fn (array $g): BrokenGroup => BrokenGroup::fromArray($g), array_filter($groups, 'is_array')));
    }

    /**
     * The approved request and a fresh plan cut down to what it approved, validated again.
     *
     * @return array{0: HumanApproval|null, 1: ValidatedPlan|null}
     */
    private function approved(RunContext $context, RebuiltPlan $rebuilt, ?ValidatedPlan $fresh): array
    {
        $request = self::latestDecided($this->requests->approved($context->property->id));
        if ($fresh === null || $request === null) {
            return [null, null];
        }
        try {
            $grant = $this->approvals($context)->grant((int) $request['id']);
        } catch (ApprovalRefused) {
            return [null, null];
        }
        $restricted = ApprovedSubset::restrict($grant, $fresh->plan, $fresh->bases);
        $plan = $restricted->plan->isEmpty() ? null : $rebuilt->revalidate($restricted->plan);
        if ($plan === null) {
            return [null, null];
        }

        return [new HumanApproval((string) $grant->approvalId, $grant->propertyId, PlanHash::of($plan->plan), $grant->planDay, $grant->approvedBy, $grant->approvedAt, $grant->listingChanges()), $plan];
    }

    /**
     * The approval decided last: a new approval or proposal ends the older ones of its kind
     * (ApprovalService), so this is the one the person just gave, never an older one left behind.
     *
     * @param list<array<string, mixed>> $approved
     *
     * @return array<string, mixed>|null
     */
    private static function latestDecided(array $approved): ?array
    {
        usort($approved, static fn (array $a, array $b): int => [$a['decided_at'], $a['id']] <=> [$b['decided_at'], $b['id']]);

        return $approved === [] ? null : $approved[count($approved) - 1];
    }

    /**
     * The plan without the price cuts a cancellation wave holds, validated again.
     *
     * @return array{0: ValidatedPlan|null, 1: list<array<string, mixed>>}
     */
    private function held(RunContext $context, RebuiltPlan $rebuilt, ?ValidatedPlan $plan): array
    {
        $hold = (new WaveHoldService($this->holds, $this->capabilities, $this->clock, $context->rules->waves()))->active($context->property->id);
        if ($plan === null || $hold === null) {
            return [$plan, []];
        }
        $rooms = [];
        foreach ($context->rooms as $room) {
            $rooms[$room->code] = $room;
        }
        $result = (new CancellationHold($context->rules->waves()))->apply($plan->plan, $hold, $rooms, $rebuilt->calendar(), $this->clock->now());
        if ($result->held === []) {
            return [$plan, []];
        }
        $held = array_map(static fn (HeldEntry $e): array => [
            'subject' => $e->subject->value,
            'room' => $e->roomCode,
            'night' => $e->night?->format('Y-m-d'),
            'why' => $e->why,
        ], $result->held);

        return [$result->plan->isEmpty() ? null : $rebuilt->revalidate($result->plan), $held];
    }

    private function approvals(RunContext $context): ApprovalService
    {
        return new ApprovalService($this->requests, $this->capabilities, $this->clock, $context->rules->approval());
    }
}
