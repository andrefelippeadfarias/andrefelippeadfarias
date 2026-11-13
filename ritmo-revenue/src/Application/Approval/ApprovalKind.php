<?php

declare(strict_types=1);

namespace Ritmo\Application\Approval;

/**
 * What a person is asked to approve.
 */
enum ApprovalKind: string
{
    /** The date rules of a plan (items and deletions). */
    case Plan = 'plan';

    /** Listing values (minimum, base, maximum): always a request of their own, approved one by one, never in autonomous mode. */
    case Listing = 'listing';

    /** The undo of changes the plugin made, asked by a person. */
    case Undo = 'undo';
}
