<?php

declare(strict_types=1);

namespace Ritmo\Domain\Plan;

use DateTimeImmutable;
use Ritmo\Domain\Support\Clock;
use Ritmo\Domain\Support\Days;

/**
 * A plan that passed the Validator, with the instant, the "today" and the rule set it was
 * validated against.
 *
 * The constructor is private and there is no factory: only Validator::validate() creates one,
 * so holding a ValidatedPlan is the proof that the plan was validated. Whoever writes must still
 * check isCurrent(): a plan validated yesterday is redone, never written.
 */
final class ValidatedPlan
{
    /**
     * @param DateTimeImmutable $validatedAt instant of the validation
     * @param DateTimeImmutable $today       the property's "today" the plan was validated for (date-only)
     * @param string            $timezone    timezone of the property, which defines that "today"
     * @param string            $rulesHash   RuleSet::hash() of the rules it was validated against
     * @param list<PlanIssue>   $warnings
     */
    private function __construct(
        public readonly Plan $plan,
        public readonly DateTimeImmutable $validatedAt,
        public readonly DateTimeImmutable $today,
        public readonly string $timezone,
        public readonly string $rulesHash,
        public readonly array $warnings,
    ) {
    }

    /**
     * True while the property's "today" is still the day the plan was validated for.
     */
    public function isCurrent(Clock $clock): bool
    {
        return Days::key($clock->today($this->timezone)) === Days::key($this->today);
    }
}
