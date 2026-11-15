<?php

declare(strict_types=1);

namespace Ritmo\Application\Run;

use Ritmo\Application\Alerts\AlertService;
use Ritmo\Application\ShadowRun\RunContext;
use Ritmo\Application\Write\Execute\PmsGuard;
use Ritmo\Application\Write\Execute\StepResult;
use Ritmo\Application\Write\Execute\StopReason;
use Ritmo\Application\Write\Execute\WriteExecutor;
use Ritmo\Application\Write\Execute\WriteOutcome;
use Ritmo\Application\Write\Journal\OpStatus;
use Ritmo\Domain\Alerts\AlertCode;
use Ritmo\Domain\Alerts\AlertRecord;
use Ritmo\Domain\Alerts\OperationAlerts;
use Ritmo\Domain\Support\Clock;

/**
 * What a write did, in numbers for the run's summary, and the alert of a write that failed or left
 * something unconfirmed (write_failed: the first failure, the entries it left and the groups left
 * open, which the next run reads back and completes first). The outcome says whether everything
 * planned was taken (written), some of it (partly) or none of it (failed); the nights of a room
 * left out because its listing could not be recalculated (the recalculation limit) are counted by
 * room and raise recalc_quota_exhausted, so an approved night is never skipped in silence.
 */
final class WriteReport
{
    /** Statuses of a step whose request left for the system. */
    private const SENT = [OpStatus::Accepted, OpStatus::Stored, OpStatus::Rejected, OpStatus::RateLimited, OpStatus::Unknown, OpStatus::Mismatch];

    /** Statuses of a step that went wrong. */
    private const FAILED = [OpStatus::Rejected, OpStatus::RateLimited, OpStatus::Unknown, OpStatus::Mismatch, OpStatus::Quarantined, OpStatus::NotReady];

    /** Stops that are not a failure of the systems: the run stopped on purpose or ran out of time. */
    private const CALM_STOPS = [StopReason::KillSwitch, StopReason::LockLost, StopReason::DayTurned, StopReason::TokenExpired, StopReason::TimeBudget];

    public const OUTCOME_WRITTEN = 'written';
    public const OUTCOME_PARTLY = 'partly';
    public const OUTCOME_FAILED = 'failed';

    public function __construct(
        private readonly AlertService $alerts,
        private readonly Clock $clock,
    ) {
    }

    /**
     * @return array{writes_sent: int, writes_taken: int, steps: array<string, int>, stop: string|null, alerts: list<string>, failed: bool, outcome: string, not_recalculated: array<string, int>, sync_now: bool, broken_groups: int, actor: string}
     */
    public function summarize(RunContext $context, WriteOutcome $outcome, string $actor): array
    {
        $steps = [];
        $sent = 0;
        $firstFailure = null;
        foreach ($outcome->results as $result) {
            $steps[$result->status->value] = ($steps[$result->status->value] ?? 0) + 1;
            $sent += in_array($result->status, self::SENT, true) ? 1 : 0;
            if ($firstFailure === null && in_array($result->status, self::FAILED, true)) {
                $firstFailure = $result;
            }
        }
        ksort($steps);
        $stop = $outcome->stopReason;
        $failed = $firstFailure !== null || ($stop !== null && !in_array($stop, self::CALM_STOPS, true)) || $outcome->brokenGroups !== [];
        if ($failed) {
            $this->raise($context, $outcome, $firstFailure);
        }
        $notRecalculated = self::notRecalculated($outcome);
        $raised = $this->alerts->raiseAll(array_map(static fn (string $room) => OperationAlerts::recalcQuotaExhausted($context->property->id, (string) $room), array_keys($notRecalculated)), $context->runId);
        // a room whose price steps all went through no longer waits for its quota
        $written = array_values(array_unique(array_map(static fn (StepResult $r): string => $r->step->roomCode, array_filter($outcome->results, static fn (StepResult $r): bool => $r->status === OpStatus::Accepted || $r->status === OpStatus::Stored))));
        $this->alerts->settle($context->property->id, [AlertCode::RecalcQuotaExhausted], $raised, static fn (AlertRecord $a): bool => in_array($a->roomCode, $written, true) && !isset($notRecalculated[$a->roomCode]));
        $taken = count(array_filter($outcome->results, static fn (StepResult $r): bool => self::taken($r)));
        $complete = $taken === count($outcome->results) && !$failed;

        return [
            'writes_sent' => $sent,
            'writes_taken' => $taken,
            'steps' => $steps,
            'stop' => $stop?->value,
            'alerts' => $outcome->alerts,
            'failed' => $failed,
            'outcome' => $complete ? self::OUTCOME_WRITTEN : ($taken === 0 ? self::OUTCOME_FAILED : self::OUTCOME_PARTLY),
            'not_recalculated' => $notRecalculated,
            'sync_now' => in_array('sync_now_requested', $outcome->alerts, true),
            'broken_groups' => count($outcome->brokenGroups),
            'actor' => $actor,
        ];
    }

    /**
     * A step whose values are in the system: taken by it, or left out because the PMS already had them.
     */
    private static function taken(StepResult $result): bool
    {
        return in_array($result->status, [OpStatus::Accepted, OpStatus::Stored], true)
            || ($result->status === OpStatus::Skipped && $result->reason === PmsGuard::AT_TARGET);
    }

    /**
     * Room code => nights of price steps left out because the listing could not be recalculated.
     *
     * @return array<string, int>
     */
    private static function notRecalculated(WriteOutcome $outcome): array
    {
        $out = [];
        foreach ($outcome->results as $result) {
            if ($result->status === OpStatus::Skipped && $result->reason === WriteExecutor::QUOTA_SKIP) {
                $nights = 0;
                foreach ($result->step->nights as $list) {
                    $nights += count((array) $list);
                }
                $out[$result->step->roomCode] = ($out[$result->step->roomCode] ?? 0) + $nights;
            }
        }
        ksort($out);

        return $out;
    }

    private function raise(RunContext $context, WriteOutcome $outcome, ?StepResult $failure): void
    {
        $left = 0;
        foreach ($outcome->results as $result) {
            $left += in_array($result->status, [OpStatus::Planned, OpStatus::Abandoned, OpStatus::Skipped], true) ? 1 : 0;
        }
        $step = $failure?->step;
        $this->alerts->raise(OperationAlerts::writeFailed(
            $context->property->id,
            $step === null ? 'pricing' : ($step->operation->isPms() ? 'pms' : 'pricing'),
            $failure?->httpStatus,
            $step?->number ?? 0,
            $left,
            count($outcome->brokenGroups),
            $this->clock->now(),
        ), $context->runId);
    }
}
