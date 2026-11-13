<?php

declare(strict_types=1);

namespace Ritmo\Application\Write\Execute;

use Ritmo\Application\Write\Journal\OpStatus;
use Ritmo\Application\Write\Schedule\WriteStep;
use Ritmo\Contracts\WriteOperation;

/**
 * The state of one write run while it executes: each step's result, the pricing nights accepted
 * (for "pricing first"), the listings left without a recalculation, why it stopped and the alerts.
 */
final class RunProgress
{
    /** @var array<int, StepResult> */
    private array $results = [];

    /** @var array<string, array<string, true>> */
    private array $pricingDone = [];

    /** @var array<string, true> */
    private array $notRecalculated = [];

    /** @var array<string, true> */
    private array $alerts = [];

    private ?StopReason $stop = null;

    private bool $pricingRateLimited = false;

    public function record(StepResult $result): void
    {
        $this->results[$result->step->number] = $result;
        $step = $result->step;
        $written = in_array($result->status, [OpStatus::Accepted, OpStatus::Stored], true);
        if ($written && ($step->operation === WriteOperation::OverridesWrite || $step->operation === WriteOperation::OverridesDelete)) {
            foreach ($step->dates() as $night) {
                $this->pricingDone[$step->roomCode][$night] = true;
            }
        }
    }

    public function stop(StopReason $reason, ?WriteStep $step = null): void
    {
        $this->stop = $reason;
        $this->alert('write_stopped');
        if ($reason === StopReason::RateLimited && $step !== null && !$step->operation->isPms()) {
            $this->pricingRateLimited = true;
        }
    }

    public function stopped(): ?StopReason
    {
        return $this->stop;
    }

    public function pricingRateLimited(): bool
    {
        return $this->pricingRateLimited;
    }

    public function alert(string $code): void
    {
        $this->alerts[$code] = true;
    }

    public function notRecalculated(string $listing): void
    {
        $this->notRecalculated[$listing] = true;
    }

    public function isNotRecalculated(string $listing): bool
    {
        return isset($this->notRecalculated[$listing]);
    }

    /**
     * @return array<string, array<string, true>>
     */
    public function pricingDone(): array
    {
        return $this->pricingDone;
    }

    /**
     * @return array<int, StepResult>
     */
    public function results(): array
    {
        return $this->results;
    }

    /**
     * @param list<string> $nearNights tonight and tomorrow (YYYY-MM-DD)
     */
    public function outcome(array $nearNights): WriteOutcome
    {
        ksort($this->results);
        $broken = $this->brokenGroups();
        foreach ($this->results as $result) {
            $this->alertFor($result, $nearNights);
        }
        if ($broken !== []) {
            $this->alert('night_without_rule');
        }

        return new WriteOutcome(array_values($this->results), $this->stop, array_keys($this->alerts), $broken);
    }

    /**
     * @param list<string> $nearNights
     */
    private function alertFor(StepResult $result, array $nearNights): void
    {
        $codes = [OpStatus::Unknown->value => 'write_unknown_outcome', OpStatus::Mismatch->value => 'write_mismatch', OpStatus::Quarantined->value => 'write_quarantined'];
        if (isset($codes[$result->status->value])) {
            $this->alert($codes[$result->status->value]);
        }
        if ($result->reason === PmsGuard::PRICING_FIRST) {
            $this->alert('pricing_first_skipped');
        }
        $written = in_array($result->status, [OpStatus::Accepted, OpStatus::Stored], true);
        if ($written && !$result->step->operation->isPms() && array_intersect($result->step->dates(), $nearNights) !== []) {
            $this->alert('sync_now_requested');
        }
    }

    /**
     * @return list<string> group keys whose deletion went out and whose rewrite did not
     */
    private function brokenGroups(): array
    {
        $deleted = $rewritten = [];
        foreach ($this->results as $result) {
            $step = $result->step;
            if ($step->groupKey === '' || !in_array($result->status, [OpStatus::Accepted, OpStatus::Stored, OpStatus::Unknown, OpStatus::Mismatch], true)) {
                continue;
            }
            if ($step->operation === WriteOperation::OverridesDelete) {
                $deleted[$step->groupKey] = true;
            } elseif ($result->status !== OpStatus::Unknown) {
                $rewritten[$step->groupKey] = true;
            }
        }

        return array_keys(array_diff_key($deleted, $rewritten));
    }
}
