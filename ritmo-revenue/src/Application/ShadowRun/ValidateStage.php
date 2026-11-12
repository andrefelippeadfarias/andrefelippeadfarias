<?php

declare(strict_types=1);

namespace Ritmo\Application\ShadowRun;

use Ritmo\Domain\Plan\PercentItem;
use Ritmo\Domain\Plan\PercentRounding;
use Ritmo\Domain\Plan\Plan;
use Ritmo\Domain\Plan\PlanIssue;
use Ritmo\Domain\Plan\ValidationResult;
use Ritmo\Domain\Plan\Validator;
use Ritmo\Domain\Support\Clock;

/**
 * Puts the plan of the run through the validator. Items with errors are rejected and the rest is
 * validated again, until what is left is valid; that part is converted to the percentages the
 * pricing system would receive, and items that would change nothing are set apart.
 */
final class ValidateStage implements Stage
{
    public const NAME = 'validate';

    public function __construct(private readonly Clock $clock)
    {
    }

    public function name(): string
    {
        return self::NAME;
    }

    public function run(RunContext $context): StageResult
    {
        $entries = is_array($context->state['items'] ?? null) ? $context->state['items'] : [];
        $items = array_map(static fn (array $entry) => StateCodec::toPlanItem($entry['item']), array_values($entries));
        $plan = new Plan($context->property->id, $context->today, $this->clock->now(), $items);
        $validator = new Validator($context->rules, $this->clock);

        $issues = [];
        $rejected = [];
        $result = $validator->validate($plan, $context->property, $context->rooms);
        self::collectIssues($result, $plan, $issues);
        while (!$result->isValid()) {
            $positions = $result->rejectedItems();
            if ($positions === []) {
                throw PlanRefused::withCodes($result->errorCodes());
            }
            foreach ($positions as $position) {
                $rejected[] = $plan->items[$position]->key();
            }
            $plan = $plan->without($positions);
            $result = $validator->validate($plan, $context->property, $context->rooms);
            self::collectIssues($result, $plan, $issues);
        }

        $percent = (new PercentRounding())->apply($result->plan, $context->rooms);
        $outcomes = [];
        foreach ([PlanItemStatus::WOULD_WRITE => $percent->items, PlanItemStatus::NO_CHANGE => $percent->dropped] as $status => $converted) {
            foreach ($converted as $item) {
                $outcomes[$item->item->key()] = self::outcome($status, $item, $issues);
            }
        }
        foreach ($rejected as $key) {
            $outcomes[$key] = self::outcome(PlanItemStatus::REJECTED, null, $issues, $key);
        }
        ksort($outcomes);

        $counts = array_count_values(array_column($outcomes, 'status'));

        return new StageResult(
            [
                'items' => count($outcomes),
                PlanItemStatus::WOULD_WRITE => $counts[PlanItemStatus::WOULD_WRITE] ?? 0,
                PlanItemStatus::NO_CHANGE => $counts[PlanItemStatus::NO_CHANGE] ?? 0,
                PlanItemStatus::REJECTED => $counts[PlanItemStatus::REJECTED] ?? 0,
                'issues' => self::countCodes($issues),
            ],
            ['outcomes' => $outcomes],
        );
    }

    /**
     * @param array<string, list<array{severity: string, code: string, message: string}>> $issues
     */
    private static function collectIssues(ValidationResult $result, Plan $plan, array &$issues): void
    {
        foreach ([...$result->errors, ...$result->warnings] as $issue) {
            /** @var PlanIssue $issue */
            if ($issue->itemIndex === null || !isset($plan->items[$issue->itemIndex])) {
                continue;
            }
            $issues[$plan->items[$issue->itemIndex]->key()][] = ['severity' => $issue->severity, 'code' => $issue->code, 'message' => $issue->message];
        }
    }

    /**
     * @param array<string, list<array{severity: string, code: string, message: string}>> $issues
     *
     * @return array{status: string, min_percent: int|null, max_percent: int|null, issues: list<array{severity: string, code: string, message: string}>}
     */
    private static function outcome(string $status, ?PercentItem $item, array $issues, ?string $key = null): array
    {
        $key ??= $item?->item->key() ?? '';

        return [
            'status' => $status,
            'min_percent' => $item?->minPercent,
            'max_percent' => $item?->maxPercent,
            'issues' => $issues[$key] ?? [],
        ];
    }

    /**
     * @param array<string, list<array{severity: string, code: string, message: string}>> $issues
     *
     * @return array<string, int> issue code => items
     */
    private static function countCodes(array $issues): array
    {
        $counts = [];
        foreach ($issues as $ofItem) {
            foreach (array_unique(array_column($ofItem, 'code')) as $code) {
                $counts[$code] = ($counts[$code] ?? 0) + 1;
            }
        }
        ksort($counts);

        return $counts;
    }
}
