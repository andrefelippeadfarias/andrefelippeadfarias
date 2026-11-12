<?php

declare(strict_types=1);

namespace Ritmo\Domain\Plan;

/**
 * What a PlanIssue is about, and so which list of the plan its index points at.
 */
enum IssueSubject: string
{
    case Item = 'item';
    case Deletion = 'deletion';
    case Listing = 'listing';
    /** The whole plan, or a room night without an entry of its own. */
    case Plan = 'plan';
}
