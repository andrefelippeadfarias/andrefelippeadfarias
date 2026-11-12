<?php

declare(strict_types=1);

namespace Ritmo\Domain\Plan;

use LogicException;

/**
 * A validated plan converted to percentages over the listing bases.
 */
final class PercentPlan
{
    /**
     * @param list<PercentItem> $items   what is left to write
     * @param list<PercentItem> $dropped items left out because the same percentages are already in force
     */
    public function __construct(
        public readonly ValidatedPlan $plan,
        public readonly array $items,
        public readonly array $dropped,
    ) {
    }

    /**
     * @return array<string, mixed>
     *
     * @throws LogicException always: what would be written only lives in the process that validated it
     */
    public function __serialize(): array
    {
        throw new LogicException('A converted plan cannot be serialised; validate the plan again where it is needed.');
    }

    /**
     * @param array<string, mixed> $data
     *
     * @throws LogicException always: an unserialised copy would not have passed the Validator
     */
    public function __unserialize(array $data): void
    {
        throw new LogicException('A converted plan cannot be unserialised.');
    }
}
