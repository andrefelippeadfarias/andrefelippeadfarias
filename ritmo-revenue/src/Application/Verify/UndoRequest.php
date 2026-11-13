<?php

declare(strict_types=1);

namespace Ritmo\Application\Verify;

use Ritmo\Application\Approval\ApprovalGrant;
use Ritmo\Domain\Plan\PlanIssue;
use Ritmo\Domain\Write\Change\UndoDraft;

/**
 * An undo asked by a person: the draft (with the nights it leaves alone and why), what the
 * Validator refused, and the approvals of the person for the rest. Nothing is written yet: the
 * approved write uses the grants with fresh reads and the write gate.
 */
final class UndoRequest
{
    /**
     * @param list<PlanIssue>     $refused the Validator's errors (those entries are not undone)
     * @param list<ApprovalGrant> $grants
     */
    public function __construct(
        public readonly UndoDraft $draft,
        public readonly array $refused,
        public readonly array $grants,
    ) {
    }
}
