<?php

declare(strict_types=1);

namespace Ritmo\Application\Approval;

/**
 * The entries a person approved after the groups were closed, the ones not approved, and the
 * ones added because their group was selected.
 */
final class ApprovalSelection
{
    /**
     * @param list<string> $approved entry keys
     * @param list<string> $rejected entry keys
     * @param list<string> $added    keys approved only because another member of their group was
     */
    public function __construct(
        public readonly array $approved,
        public readonly array $rejected,
        public readonly array $added,
    ) {
    }
}
