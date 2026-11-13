<?php

declare(strict_types=1);

namespace Ritmo\Application\Approval;

use Ritmo\Domain\Plan\Plan;

/**
 * A plan rebuilt for an approved write, cut down to what the person approved.
 */
final class RestrictedPlan
{
    /**
     * @param list<string> $needsNewApproval entries approved before whose sent fields changed since (shown again, never written)
     * @param list<string> $notApproved      entries of the new plan the person did not approve
     */
    public function __construct(
        public readonly Plan $plan,
        public readonly array $needsNewApproval,
        public readonly array $notApproved,
    ) {
    }
}
