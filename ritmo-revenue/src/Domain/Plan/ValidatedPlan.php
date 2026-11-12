<?php

declare(strict_types=1);

namespace Ritmo\Domain\Plan;

use DateTimeImmutable;
use LogicException;
use Ritmo\Domain\Support\Clock;
use Ritmo\Domain\Support\Days;

/**
 * A plan that passed the Validator, with the instant, the "today", the rule set and the listing
 * bases it was validated against.
 *
 * The constructor is private and there is no factory: only Validator::validate() creates one,
 * and the Validator keeps track of the plans it sealed (Validator::sealed()), so an object made
 * some other way (Reflection, a copy of its properties) is not taken as validated. It cannot be
 * serialised nor unserialised: a stored or forged copy is never a validated plan. Whoever writes
 * must still check isCurrent(): a plan validated yesterday is redone, never written.
 */
final class ValidatedPlan
{
    /**
     * @param DateTimeImmutable    $validatedAt instant of the validation
     * @param DateTimeImmutable    $today       the property's "today" the plan was validated for (date-only)
     * @param string               $timezone    timezone of the property, which defines that "today"
     * @param string               $rulesHash   RuleSet::hash() of the rules it was validated against
     * @param list<PlanIssue>      $warnings
     * @param array<string, float> $bases       room code => listing base the items were converted with
     */
    private function __construct(
        public readonly Plan $plan,
        public readonly DateTimeImmutable $validatedAt,
        public readonly DateTimeImmutable $today,
        public readonly string $timezone,
        public readonly string $rulesHash,
        public readonly array $warnings,
        public readonly array $bases,
    ) {
    }

    /**
     * True while the property's "today" is still the day the plan was validated for.
     */
    public function isCurrent(Clock $clock): bool
    {
        return Days::key($clock->today($this->timezone)) === Days::key($this->today);
    }

    /**
     * @return array<string, mixed>
     *
     * @throws LogicException always: a validated plan only lives in the process that validated it
     */
    public function __serialize(): array
    {
        throw new LogicException('A validated plan cannot be serialised; validate the plan again where it is needed.');
    }

    /**
     * @param array<string, mixed> $data
     *
     * @throws LogicException always: an unserialised copy would not have passed the Validator
     */
    public function __unserialize(array $data): void
    {
        throw new LogicException('A validated plan cannot be unserialised.');
    }
}
