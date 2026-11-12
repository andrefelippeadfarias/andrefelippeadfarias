<?php

declare(strict_types=1);

namespace Ritmo\Domain\Plan;

/**
 * The outcome of validating a plan: its errors and warnings and, when there is no error, the
 * validated plan.
 */
final class ValidationResult
{
    /**
     * @param list<PlanIssue>    $errors
     * @param list<PlanIssue>    $warnings
     * @param ValidatedPlan|null $plan     present exactly when there is no error
     */
    public function __construct(
        public readonly array $errors,
        public readonly array $warnings,
        public readonly ?ValidatedPlan $plan,
    ) {
    }

    public function isValid(): bool
    {
        return $this->plan !== null;
    }

    /**
     * @return list<string>
     */
    public function errorCodes(): array
    {
        return array_map(static fn (PlanIssue $issue): string => $issue->code, $this->errors);
    }

    /**
     * @return list<string>
     */
    public function warningCodes(): array
    {
        return array_map(static fn (PlanIssue $issue): string => $issue->code, $this->warnings);
    }

    /**
     * Positions of the items that have at least one error, ascending.
     *
     * @return list<int>
     */
    public function rejectedItems(): array
    {
        $indexes = [];
        foreach ($this->errors as $issue) {
            if ($issue->itemIndex !== null) {
                $indexes[$issue->itemIndex] = true;
            }
        }
        ksort($indexes);

        return array_keys($indexes);
    }
}
