<?php

declare(strict_types=1);

namespace Ritmo\Application\ShadowRun;

use Ritmo\Domain\Plan\PlanIssue;

/**
 * Reads the validation the assembly made (the Validator runs inside the PlanAssembler, with the
 * run's calendar, orders, relations and stay rules): every item would be written, changes
 * nothing or is rejected; a plan made for another day is refused as a whole, so the run ends
 * and the next one rebuilds it. A plan whose remaining errors concern the plan as a whole keeps
 * every item rejected and its issues on show.
 */
final class ValidateStage implements Stage
{
    public const NAME = 'validate';

    public function name(): string
    {
        return self::NAME;
    }

    public function run(RunContext $context): StageResult
    {
        $refused = is_array($context->state['refused'] ?? null) ? $context->state['refused'] : null;
        if ($refused !== null && in_array(PlanIssue::STALE_PLAN, $refused, true)) {
            throw PlanRefused::withCodes($refused);
        }
        $entries = is_array($context->state['items'] ?? null) ? $context->state['items'] : [];
        $outcomes = [];
        foreach ($entries as $key => $entry) {
            $outcomes[$key] = $entry['outcome'];
        }
        ksort($outcomes);
        $counts = array_count_values(array_column($outcomes, 'status'));

        return new StageResult(
            [
                'items' => count($outcomes),
                PlanItemStatus::WOULD_WRITE => $counts[PlanItemStatus::WOULD_WRITE] ?? 0,
                PlanItemStatus::NO_CHANGE => $counts[PlanItemStatus::NO_CHANGE] ?? 0,
                PlanItemStatus::REJECTED => $counts[PlanItemStatus::REJECTED] ?? 0,
                'issues' => self::countCodes($outcomes),
                'plan_issues' => array_values(array_unique(array_column(is_array($context->state['plan_issues'] ?? null) ? $context->state['plan_issues'] : [], 'code'))),
                'refused' => $refused !== null,
            ],
            ['outcomes' => $outcomes],
        );
    }

    /**
     * @param array<string, array{issues: list<array{code: string}>}> $outcomes
     *
     * @return array<string, int> issue code => items
     */
    private static function countCodes(array $outcomes): array
    {
        $counts = [];
        foreach ($outcomes as $outcome) {
            foreach (array_unique(array_column($outcome['issues'], 'code')) as $code) {
                $counts[$code] = ($counts[$code] ?? 0) + 1;
            }
        }
        ksort($counts);

        return $counts;
    }
}
