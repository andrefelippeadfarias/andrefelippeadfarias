<?php

declare(strict_types=1);

namespace Ritmo\Application\Schedule;

use DateTimeImmutable;
use Ritmo\Application\Alerts\AlertService;
use Ritmo\Application\EmergencySwitch;
use Ritmo\Application\ShadowRun\ShadowRunService;
use Ritmo\Contracts\KeyValueStore;
use Ritmo\Contracts\Logger;
use Ritmo\Domain\Alerts\AlertCode;
use Ritmo\Domain\Alerts\AlertDraft;
use Ritmo\Domain\Alerts\OperationAlerts;
use Ritmo\Domain\Model\Property;
use Ritmo\Domain\Operations\DueSlotPolicy;
use Ritmo\Domain\Operations\LocalTime;
use Ritmo\Domain\Operations\PastRun;
use Ritmo\Domain\Operations\RunKind;
use Ritmo\Domain\Operations\ScheduleConfig;
use Ritmo\Domain\Operations\SlotDecision;
use Ritmo\Domain\Operations\SlotKind;
use Ritmo\Domain\Operations\SlotPlanner;
use Ritmo\Domain\Operations\SlotRecord;
use Ritmo\Domain\Operations\SlotStatus;
use Ritmo\Domain\Support\Clock;
use Ritmo\Domain\Support\Days;
use Ritmo\Infrastructure\Db\Repository\PropertyRepository;
use Ritmo\Infrastructure\Db\Repository\RunRepository;
use Ritmo\Infrastructure\Lock\DbLock;
use Ritmo\Infrastructure\Schedule\OperationStateRepository;
use Ritmo\Infrastructure\Schedule\ScheduledRunRepository;
use Throwable;

/**
 * One tick of the scheduler (the WP-Cron event every five minutes; operations spec 3.3).
 *
 * Under the site tick lock, for each property (oldest due slot first, while the budget lasts):
 * lift an ended pause; record the slots that came due since the last tick (once each); continue
 * the run in progress, if any, and stop there; otherwise let {@see DueSlotPolicy} choose the slot
 * to run and close the others, and start the run through the {@see RunLauncher}, which holds the
 * property's single run lock. Under the emergency switch the run starts as a forced shadow run
 * (or the slots are skipped when the schedule stops reads too). Every slot ends with a status;
 * late, missed and failed slots raise their alerts; a run that failed for a connector reason gets
 * one retry slot. An exception of one property is logged with its class only and never stops the
 * others or reaches WP-Cron. The first tick of each UTC day also purges the old slot rows and
 * booking versions ({@see ScheduleHousekeeping}).
 */
final class SchedulerTick
{
    public const TICK_LOCK = 'scheduler_tick';

    public const HEARTBEAT_KEY = 'scheduler_last_tick';

    public const DEFAULT_BUDGET_SECONDS = 40;

    /** After a long stop, the slots of at most this many days are recorded (all as missed). */
    private const MAX_MATERIALISE_DAYS = 31;

    private const RUN_HISTORY = 30;

    public function __construct(
        private readonly PropertyRepository $properties,
        private readonly ScheduledRunRepository $slots,
        private readonly OperationStateRepository $states,
        private readonly RunRepository $runs,
        private readonly DbLock $locks,
        private readonly KeyValueStore $store,
        private readonly EmergencySwitch $switch,
        private readonly RunLauncher $launcher,
        private readonly ScheduleSource $schedules,
        private readonly AlertService $alerts,
        private readonly ScheduleHousekeeping $housekeeping,
        private readonly Clock $clock,
        private readonly Logger $logger,
    ) {
    }

    /**
     * Never throws: WP-Cron gets nothing back from a broken tick, the log gets the exception class.
     */
    public function run(int $budgetSeconds = self::DEFAULT_BUDGET_SECONDS): TickReport
    {
        try {
            return $this->locked($budgetSeconds);
        } catch (Throwable $e) {
            $this->logger->error('The scheduler tick failed; the next tick tries again.', ['exception' => $e::class]);

            return new TickReport(false, [], true);
        }
    }

    private function locked(int $budgetSeconds): TickReport
    {
        $now = $this->clock->now();
        $this->store->set(self::HEARTBEAT_KEY, AlertDraft::instant($now));
        $lock = $this->locks->acquire(self::TICK_LOCK, max(1, $budgetSeconds) + 60);
        if ($lock === null) {
            return new TickReport(true);
        }

        try {
            $this->alerts->resolveKey(AlertDraft::keyFor(AlertCode::SchedulerStalled, ''));
            $deadline = $now->modify(sprintf('+%d seconds', max(1, $budgetSeconds)));
            $report = [];
            foreach ($this->byOldestDue($now) as $property) {
                if ($this->clock->now() >= $deadline) {
                    $report[$property->id] = TickReport::DEFERRED;
                    continue;
                }
                $report[$property->id] = $this->guarded($property);
            }
            $this->housekeeping->daily();

            return new TickReport(false, $report);
        } finally {
            $this->locks->release($lock);
        }
    }

    private function guarded(Property $property): string
    {
        try {
            return $this->property($property);
        } catch (Throwable $e) {
            $this->logger->error('The scheduler failed on a property; the others continue.', ['property_id' => $property->id, 'exception' => $e::class]);

            return TickReport::ERROR;
        }
    }

    private function property(Property $property): string
    {
        $schedule = $this->schedules->schedule($property);
        $planner = new SlotPlanner($schedule, $property->timezone);
        $policy = new DueSlotPolicy($schedule);
        $now = $this->clock->now();

        $state = $this->states->get($property->id);
        if ($state->pauseEndedAt($now)) {
            $this->states->resume($property->id);
            $this->logger->info('A paused property resumed at the end of its pause.', ['property_id' => $property->id]);
        }
        $this->materialise($property->id, $planner, $state->slotsConsideredUntil, $now);
        $this->reconcile($property->id);

        $paused = $this->states->get($property->id)->isPausedAt($now);
        $open = $this->launch(fn (): ?LaunchResult => $paused
            ? $this->launcher->stopOpen($property, 'The property is paused.')
            : $this->launcher->continueOpen($property, $schedule->runBudgetSeconds));
        if ($open !== null && $open->status === LaunchStatus::Busy) {
            return TickReport::WAITING;
        }
        if ($open !== null) {
            $this->afterRun($property, $open, $planner, $policy);
            if ($open->failure !== FailureKind::DayChanged) {
                return TickReport::CONTINUED;
            }
        }

        $due = $this->slots->due($property->id, $now);
        if ($due === []) {
            return TickReport::IDLE;
        }
        if ($this->switch->isEngaged() && $schedule->stopReadsUnderEmergency) {
            foreach ($due as $record) {
                $this->slots->close($record->id, SlotStatus::SkippedBlocked, $now, 'The emergency switch is engaged.');
            }

            return TickReport::BLOCKED;
        }

        $decision = $policy->choose(
            array_map(static fn (SlotRecord $r) => $r->slot, $due),
            $this->pastRuns($property),
            $this->clock->today($property->timezone),
            $now,
            $paused,
        );

        return $this->apply($property, $schedule, $planner, $policy, $decision, $due, $now);
    }

    /**
     * @param list<SlotRecord> $due
     */
    private function apply(Property $property, ScheduleConfig $schedule, SlotPlanner $planner, DueSlotPolicy $policy, SlotDecision $decision, array $due, DateTimeImmutable $now): string
    {
        $byKey = [];
        foreach ($due as $record) {
            $byKey[$record->slot->key()] = $record;
        }
        foreach ($decision->closures as [$slot, $status]) {
            $this->slots->close($byKey[$slot->key()]->id, $status, $now);
        }
        if ($decision->missedGap !== null) {
            $this->alerts->raise(OperationAlerts::runMissed($property->id, $decision->missedGap[0], $decision->missedGap[1]));
        }
        if ($decision->run === null || $decision->runKind === null) {
            return TickReport::IDLE;
        }

        $record = $byKey[$decision->run->key()];
        $kind = $decision->runKind;
        $result = $this->launch(fn (): LaunchResult => $this->launcher->start($property, $kind, $this->switch->isEngaged(), $schedule->runBudgetSeconds))
            ?? new LaunchResult(LaunchStatus::Failed, null, FailureKind::Other);
        if ($result->status === LaunchStatus::Busy) {
            return TickReport::WAITING;
        }
        $this->slots->started($record->id, $result->runId, $now, $decision->delaySeconds, $decision->promoted, $result->status->slotStatus());
        if ($result->status->isFinal()) {
            $this->slots->close($record->id, $result->status->slotStatus(), $this->clock->now(), $result->note);
            $this->retryIfDue($property->id, $record, $result, $planner, $policy);
        }
        if ($decision->run->kind->isScheduled()) {
            $this->late($property->id, $decision);
        }

        return TickReport::STARTED;
    }

    /**
     * Closes the slot of a run that ended on this tick, and gives a connector failure its retry.
     */
    private function afterRun(Property $property, LaunchResult $result, SlotPlanner $planner, DueSlotPolicy $policy): void
    {
        if (!$result->status->isFinal() || $result->runId === null) {
            return;
        }
        $record = $this->slots->forRun($result->runId);
        if ($record === null || $record->status->isFinal()) {
            return;
        }
        $this->slots->close($record->id, $result->status->slotStatus(), $this->clock->now(), $result->note);
        $this->retryIfDue($property->id, $record, $result, $planner, $policy);
    }

    /**
     * Closes the slots whose run ended outside the scheduler (a request continued it), so no slot
     * stays running forever (O7).
     */
    private function reconcile(string $propertyId): void
    {
        foreach ($this->slots->running($propertyId) as $record) {
            $run = $record->runId === null ? null : $this->runs->find($record->runId);
            if ($run !== null && $run['status'] !== RunRepository::RUNNING) {
                $this->slots->close($record->id, SlotStatus::from($run['status']), $run['finished_at'] ?? $this->clock->now(), (string) ($run['error'] ?? ''));
            }
        }
    }

    private function retryIfDue(string $propertyId, SlotRecord $record, LaunchResult $result, SlotPlanner $planner, DueSlotPolicy $policy): void
    {
        if ($result->status !== LaunchStatus::Failed || $result->failure !== FailureKind::Connector) {
            return;
        }
        if ($record->slot->kind !== SlotKind::Full && $record->slot->kind !== SlotKind::Light) {
            return;
        }
        $retry = $policy->retryAfter($this->clock->now(), $planner);
        if ($retry !== null) {
            $this->slots->materialise($propertyId, $retry);
        }
    }

    private function late(string $propertyId, SlotDecision $decision): void
    {
        if ($decision->late && $decision->run !== null) {
            $this->alerts->raise(OperationAlerts::runLate($propertyId, $decision->run->localKey(), $decision->delaySeconds));
        } else {
            $this->alerts->resolveKey(AlertDraft::keyFor(AlertCode::RunLate, $propertyId));
        }
    }

    private function materialise(string $propertyId, SlotPlanner $planner, ?DateTimeImmutable $consideredUntil, DateTimeImmutable $now): void
    {
        // A property seen for the first time starts with the slots of its current local day: the
        // days before it existed in the scheduler were not missed.
        $floor = $now->modify(sprintf('-%d days', self::MAX_MATERIALISE_DAYS));
        $from = $consideredUntil ?? $planner->occurrence($planner->localDate($now), LocalTime::fromString('00:00'))->modify('-1 second');
        if ($from < $floor) {
            $from = $floor;
        }
        foreach ($planner->slotsBetween($from, $now) as $slot) {
            $this->slots->materialise($propertyId, $slot);
        }
        if ($consideredUntil === null || $now > $consideredUntil) {
            $this->states->setSlotsConsideredUntil($propertyId, $now);
        }
    }

    /**
     * Runs a launcher call; an exception becomes a failed run with the exception class as note.
     *
     * @param callable(): (LaunchResult|null) $call
     */
    private function launch(callable $call): ?LaunchResult
    {
        try {
            return $call();
        } catch (Throwable $e) {
            $this->logger->error('A run failed with an exception.', ['exception' => $e::class]);

            return new LaunchResult(LaunchStatus::Failed, null, FailureKind::Other, $e::class);
        }
    }

    /**
     * @return list<PastRun>
     */
    private function pastRuns(Property $property): array
    {
        $past = [];
        foreach ($this->runs->latest($property->id, self::RUN_HISTORY) as $run) {
            $kind = $run['kind'] === ShadowRunService::KIND ? RunKind::Full : RunKind::tryFrom($run['kind']);
            if ($kind !== null) {
                $past[] = new PastRun($kind, $run['started_at'], $run['run_day'] ?? Days::localDate($run['started_at'], $property->timezone), $run['status'] === RunRepository::COMPLETED);
            }
        }

        return $past;
    }

    /**
     * @return list<Property>
     */
    private function byOldestDue(DateTimeImmutable $now): array
    {
        $properties = $this->properties->all();
        $order = [];
        foreach ($properties as $property) {
            $order[$property->id] = $this->slots->oldestDueAt($property->id, $now)?->getTimestamp() ?? PHP_INT_MAX;
        }
        usort($properties, static fn (Property $a, Property $b): int => [$order[$a->id], $a->id] <=> [$order[$b->id], $b->id]);

        return $properties;
    }
}
