<?php

declare(strict_types=1);

namespace Ritmo\Application\Approval;

use RuntimeException;

/**
 * An approval request or decision the service refuses, with a reason code.
 */
final class ApprovalRefused extends RuntimeException
{
    public const NOT_FOUND = 'not_found';
    public const NOT_PENDING = 'not_pending';
    public const NOT_APPROVED = 'not_approved';
    public const NO_CAPABILITY = 'no_capability';
    public const EXPIRED = 'approval_expired';
    public const NOTHING_SELECTED = 'nothing_selected';
    public const UNKNOWN_ENTRY = 'unknown_entry';
    public const LISTING_NEEDS_EXPLICIT_APPROVAL = 'listing_needs_explicit_approval';
    public const UNSEALED_PLAN = 'unsealed_plan';
    public const LISTING_NEEDS_SAFETY = 'listing_needs_safety';
    public const LISTING_NOT_CONFIRMED = 'listing_not_confirmed';
    public const NOTE_PRIVATE = 'note_private';

    public function __construct(public readonly string $reason, string $message)
    {
        parent::__construct($message);
    }
}
