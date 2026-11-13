<?php

declare(strict_types=1);

namespace Ritmo\Application\Write\Execute;

use Ritmo\Application\Write\Journal\OpStatus;

/**
 * What a write run did, step by step, why it stopped (null: every step was tried) and the alerts
 * it raises (codes of WRITE.md: write_stopped, night_without_rule, write_unknown_outcome,
 * write_mismatch, write_quarantined, pricing_first_skipped, not_confirmed_quota, sync_now_requested).
 */
final class WriteOutcome
{
    /**
     * @param list<StepResult> $results in schedule order
     * @param list<string>     $alerts
     * @param list<string>     $brokenGroups group keys whose deletion went out and whose rewrite did not
     */
    public function __construct(
        public readonly array $results,
        public readonly ?StopReason $stopReason,
        public readonly array $alerts,
        public readonly array $brokenGroups = [],
    ) {
    }

    /**
     * @return list<int> numbers of the steps with that status
     */
    public function stepsWith(OpStatus $status): array
    {
        return array_values(array_map(
            static fn (StepResult $r): int => $r->step->number,
            array_filter($this->results, static fn (StepResult $r): bool => $r->status === $status)
        ));
    }

    public function statusOf(int $number): ?OpStatus
    {
        foreach ($this->results as $result) {
            if ($result->step->number === $number) {
                return $result->status;
            }
        }

        return null;
    }
}
