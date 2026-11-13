<?php

declare(strict_types=1);

namespace Ritmo\Application\Write\Execute;

use Ritmo\Application\EmergencySwitch;
use Ritmo\Application\Write\Gate\WriteGate;
use Ritmo\Application\Write\Journal\OpStatus;
use Ritmo\Application\Write\Journal\StateReader;
use Ritmo\Application\Write\Journal\StoredState;
use Ritmo\Application\Write\Journal\WriteJournal;
use Ritmo\Application\Write\Quota\RecalcGuard;
use Ritmo\Application\Write\Schedule\WriteStep;
use Ritmo\Application\Write\WriteConfig;
use Ritmo\Contracts\Exception\ConnectorException;
use Ritmo\Contracts\Exception\WriteBlocked;
use Ritmo\Contracts\WriteOperation;
use Ritmo\Domain\Support\Clock;
use Ritmo\Domain\Support\Days;

/**
 * Executes the steps of a token, in series, one call at a time:
 * - before every step: the site's write lock (heartbeat), the emergency switch, the property's
 *   day (a plan of another day is never written), the token, the time left (a deletion starts
 *   only with time for its rewrite too); any of them ends the run before the step;
 * - every step is journaled as planned before the first call and as sending before its own call;
 * - the first refusal, rate limit, server error or lost answer stops the run: no retry, no next
 *   step (the outcome of a lost answer is read back by a later run before anything is resent);
 * - a recalculation goes only when the quota allows it (recorded before the call); a listing
 *   whose recalculation was not allowed gets none of its writes;
 * - a body refused too many runs in a row is quarantined, not sent;
 * - each PMS block is checked right before it (pricing first, values changed since read) and read
 *   back right after it; the pricing writes are read back at the end (no quota).
 * The token is revoked when the run ends, however it ends.
 */
final class WriteExecutor
{
    private const QUOTA_SKIP = 'not_recalculated';

    private readonly StepSender $sender;

    public function __construct(
        private readonly WriteGate $gate,
        private readonly EmergencySwitch $switch,
        private readonly Clock $clock,
        private readonly WriteJournal $journal,
        private readonly WriteConfig $config = new WriteConfig(),
        private readonly ?RecalcGuard $recalc = null,
    ) {
        $this->sender = new StepSender();
    }

    public function execute(WriteRun $run): WriteOutcome
    {
        $progress = new RunProgress();
        $steps = $run->issued->steps;
        $reader = new StateReader($run->pricing, $run->pms);
        try {
            $before = $reader->forSteps($steps);
        } catch (ConnectorException) {
            $this->gate->revoke($run->issued->token);
            $progress->stop(StopReason::ReadFailed);

            return $progress->outcome([]);
        }
        $rows = $this->journal->plan($run->runId, $run->property->id, $run->issued, $before);
        try {
            foreach ($steps as $index => $step) {
                $stop = $progress->stopped() ?? $this->stopBefore($run, $step, $steps[$index + 1] ?? null);
                if ($stop !== null && $progress->stopped() === null) {
                    $progress->stop($stop);
                }
                if ($stop !== null) {
                    $this->abandon(array_slice($steps, $index), $rows, $progress);
                    break;
                }
                $progress->record($this->runStep($run, $step, $rows[$step->number], $before, $reader, $progress));
            }
        } finally {
            $this->gate->revoke($run->issued->token);
        }
        $this->checkPricingStored($progress, $rows, $before, $reader);
        $today = $this->clock->today($run->property->timezone);

        return $progress->outcome([Days::key($today), Days::key(Days::add($today, 1))]);
    }

    private function stopBefore(WriteRun $run, WriteStep $step, ?WriteStep $next): ?StopReason
    {
        $now = $this->clock->now();
        $needs = $this->config->callTimeoutSeconds * ($step->groupKey !== '' && $next !== null && $next->groupKey === $step->groupKey ? 2 : 1);

        return match (true) {
            ($run->heartbeat)() !== true => StopReason::LockLost,
            $this->switch->isEngaged() => StopReason::KillSwitch,
            !$run->plan->isCurrent($this->clock) => StopReason::DayTurned,
            !$this->gate->isLive($run->issued->token) => StopReason::TokenExpired,
            $run->deadline !== null && $now->modify(sprintf('+%d seconds', $needs)) > $run->deadline => StopReason::TimeBudget,
            default => null,
        };
    }

    private function runStep(WriteRun $run, WriteStep $step, int $row, StoredState $before, StateReader $reader, RunProgress $progress): StepResult
    {
        if ($step->operation === WriteOperation::Recalculation) {
            return $this->recalculate($run, $step, $row, $progress);
        }
        if (!$step->operation->isPms() && $progress->isNotRecalculated($step->target())) {
            return $this->finish($row, new StepResult($step, OpStatus::Skipped, self::QUOTA_SKIP));
        }
        if ($this->journal->refusals($run->property->id, $step) >= $this->config->maxIdenticalRefusals) {
            return $this->finish($row, new StepResult($step, OpStatus::Quarantined, 'refused_too_often'));
        }
        if ($step->operation->isPms()) {
            try {
                [$left, $skipped] = (new PmsGuard())->filter($step, $progress->pricingDone(), $reader->forSteps([$step]));
            } catch (ConnectorException) {
                $progress->stop(StopReason::ReadFailed, $step);

                return $this->finish($row, new StepResult($step, OpStatus::Abandoned, StopReason::ReadFailed->value));
            }
            if ($left === null) {
                return $this->finish($row, new StepResult($step, OpStatus::Skipped, $skipped[0]['reason'] ?? ''));
            }
            if ($skipped !== [] && in_array(PmsGuard::PRICING_FIRST, array_column($skipped, 'reason'), true)) {
                $progress->alert('pricing_first_skipped');
            }
            $step = $left;
        }

        return $this->send($run, $step, $row, $before, $reader, $progress, fn () => $this->sender->send($run, $step));
    }

    private function recalculate(WriteRun $run, WriteStep $step, int $row, RunProgress $progress): StepResult
    {
        $listing = $step->target();
        $verdict = $this->recalc?->verdict($listing, $run->runStart ?? $this->clock->now());
        if ($verdict === null || !$verdict->allowed) {
            $progress->notRecalculated($listing);
            $progress->alert('not_confirmed_quota');

            return $this->finish($row, new StepResult($step, OpStatus::Skipped, 'quota:' . implode(',', $verdict?->blockedBy ?? ['no_ledger'])));
        }
        $recalc = $this->recalc;

        return $this->send($run, $step, $row, new StoredState(), null, $progress, fn () => $recalc->attempt($listing, fn () => $this->sender->send($run, $step)));
    }

    /**
     * Journals the step as sending, makes the call and classifies its end.
     *
     * @param callable(): void $call
     */
    private function send(WriteRun $run, WriteStep $step, int $row, StoredState $before, ?StateReader $reader, RunProgress $progress, callable $call): StepResult
    {
        $this->journal->sending($row);
        try {
            $call();
        } catch (ConnectorException|WriteBlocked $failure) {
            [$status, $stop, $http] = StepSender::classify($failure);
            $progress->stop($stop, $step);
            $this->journal->finish($row, $status, $http, $failure->getMessage());

            return new StepResult($step, $status, $stop->value, $http);
        }
        $this->journal->finish($row, OpStatus::Accepted);
        if ($step->operation->isPms() && $reader !== null) {
            return $this->readBackPms($step, $row, $before, $reader);
        }

        return new StepResult($step, OpStatus::Accepted);
    }

    /**
     * Each PMS block is read back before the next one; a difference is reported, never rewritten.
     */
    private function readBackPms(WriteStep $step, int $row, StoredState $before, StateReader $reader): StepResult
    {
        try {
            [$status, $observed] = StoredCheck::compare($before->intended($step), $reader->forSteps([$step]), true);
        } catch (ConnectorException) {
            return new StepResult($step, OpStatus::Accepted, 'not_read_back');
        }
        $this->journal->observe($row, $status, $observed);

        return new StepResult($step, $status);
    }

    /**
     * Reads back the accepted pricing steps, one read per listing (no quota). Skipped when the
     * pricing system answered 429: the next run reads them back.
     *
     * @param array<int, int> $rows
     */
    private function checkPricingStored(RunProgress $progress, array $rows, StoredState $before, StateReader $reader): void
    {
        if ($progress->pricingRateLimited()) {
            return;
        }
        $accepted = array_values(array_filter(
            $progress->results(),
            static fn (StepResult $r): bool => $r->status === OpStatus::Accepted && in_array($r->step->operation, [WriteOperation::OverridesWrite, WriteOperation::OverridesDelete], true)
        ));
        if ($accepted === []) {
            return;
        }
        try {
            $fresh = $reader->forSteps(array_map(static fn (StepResult $r): WriteStep => $r->step, $accepted));
        } catch (ConnectorException) {
            return;
        }
        foreach ($accepted as $result) {
            [$status, $observed] = StoredCheck::compare($this->finalIntended($result->step, $accepted, $before), $fresh, false);
            $this->journal->observe($rows[$result->step->number], $status, $observed);
            $progress->record($result->with($status));
        }
    }

    /**
     * What a step's nights must hold at the end of the run: a deletion whose group rewrite was
     * accepted too ends with the rewrite's values on the nights it rewrote.
     *
     * @param list<StepResult> $accepted
     *
     * @return array<string, array<string, array{state: array<string, mixed>|null, fields: list<string>}>>
     */
    private function finalIntended(WriteStep $step, array $accepted, StoredState $before): array
    {
        $intended = $before->intended($step);
        if ($step->operation !== WriteOperation::OverridesDelete || $step->groupKey === '') {
            return $intended;
        }
        foreach ($accepted as $other) {
            if ($other->step->groupKey === $step->groupKey && $other->step->isGroupRewrite()) {
                foreach ($before->intended($other->step) as $target => $nights) {
                    foreach ($nights as $night => $want) {
                        $intended[$target][$night] = $want;
                    }
                }
            }
        }

        return $intended;
    }

    /**
     * @param list<WriteStep> $steps
     * @param array<int, int> $rows
     */
    private function abandon(array $steps, array $rows, RunProgress $progress): void
    {
        $reason = $progress->stopped()?->value ?? '';
        foreach ($steps as $step) {
            $this->journal->finish($rows[$step->number], OpStatus::Abandoned, null, $reason);
            $progress->record(new StepResult($step, OpStatus::Abandoned, $reason));
        }
    }

    private function finish(int $row, StepResult $result): StepResult
    {
        $this->journal->finish($row, $result->status, null, $result->reason);

        return $result;
    }
}
