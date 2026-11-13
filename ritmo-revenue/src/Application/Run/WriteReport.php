<?php

declare(strict_types=1);

namespace Ritmo\Application\Run;

use Ritmo\Application\Alerts\AlertService;
use Ritmo\Application\ShadowRun\RunContext;
use Ritmo\Application\Write\Execute\StepResult;
use Ritmo\Application\Write\Execute\StopReason;
use Ritmo\Application\Write\Execute\WriteOutcome;
use Ritmo\Application\Write\Journal\OpStatus;
use Ritmo\Domain\Alerts\OperationAlerts;
use Ritmo\Domain\Support\Clock;

/**
 * What a write did, in numbers for the run's summary, and the alert of a write that failed or left
 * something unconfirmed (write_failed: the first failure, the entries it left and the groups left
 * open, which the next run reads back and completes first).
 */
final class WriteReport
{
    /** Statuses of a step whose request left for the system. */
    private const SENT = [OpStatus::Accepted, OpStatus::Stored, OpStatus::Rejected, OpStatus::RateLimited, OpStatus::Unknown, OpStatus::Mismatch];

    /** Statuses of a step that went wrong. */
    private const FAILED = [OpStatus::Rejected, OpStatus::RateLimited, OpStatus::Unknown, OpStatus::Mismatch, OpStatus::Quarantined, OpStatus::NotReady];

    /** Stops that are not a failure of the systems: the run stopped on purpose or ran out of time. */
    private const CALM_STOPS = [StopReason::KillSwitch, StopReason::LockLost, StopReason::DayTurned, StopReason::TokenExpired, StopReason::TimeBudget];

    public function __construct(
        private readonly AlertService $alerts,
        private readonly Clock $clock,
    ) {
    }

    /**
     * @return array{writes_sent: int, steps: array<string, int>, stop: string|null, alerts: list<string>, failed: bool, sync_now: bool, broken_groups: int, actor: string}
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

        return [
            'writes_sent' => $sent,
            'steps' => $steps,
            'stop' => $stop?->value,
            'alerts' => $outcome->alerts,
            'failed' => $failed,
            'sync_now' => in_array('sync_now_requested', $outcome->alerts, true),
            'broken_groups' => count($outcome->brokenGroups),
            'actor' => $actor,
        ];
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
