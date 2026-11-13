<?php

declare(strict_types=1);

namespace Ritmo\Application\Approval;

/**
 * The life of a plan waiting for a person.
 */
enum ApprovalStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';
    /** The day turned, the calendar got old, the mode or the rules changed: never approved nor written. */
    case Expired = 'expired';
    /** A newer plan of the property replaced it. */
    case Superseded = 'superseded';
    /** The approved write ran. */
    case Written = 'written';
    /** Approved, but the write could not run before the approval expired. */
    case Lapsed = 'lapsed';
}
