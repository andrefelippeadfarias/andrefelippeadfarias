<?php

declare(strict_types=1);

namespace Ritmo\Application\Write\Gate;

use DomainException;

/**
 * The write gate refused to issue a token: nothing of the plan may be written. The reason code
 * says why; the message is for the log and the report (no guest data, no credential).
 */
final class WriteRefused extends DomainException
{
    public const UNSEALED_PLAN = 'unsealed_plan';
    public const WRONG_PROPERTY = 'wrong_property';
    public const STALE_PLAN = 'stale_plan';
    public const STALE_VALIDATION = 'stale_validation';
    public const STALE_READS = 'stale_reads';
    public const KILL_SWITCH = 'kill_switch';
    public const MODE_SHADOW = 'mode_shadow';
    public const MODE_NOT_AUTONOMOUS = 'mode_not_autonomous';
    public const LICENCE = 'licence';
    public const NOT_APPROVED = 'not_approved';
    public const APPROVAL_MISMATCH = 'approval_mismatch';
    public const APPROVAL_STALE = 'approval_stale';
    public const APPROVER_LACKS_CAPABILITY = 'approver_lacks_capability';
    public const RAISE_TODAY_FLOOR = 'raise_today_floor';
    public const RAISE_TODAY_CEILING = 'raise_today_ceiling';
    public const RAISE_TODAY_DELETION = 'raise_today_deletion';
    public const TONIGHT_UNKNOWN = 'tonight_unknown';
    public const FORBIDDEN_FIELD = 'forbidden_field';
    public const LOCK_NOT_HELD = 'lock_not_held';
    public const NOTHING_TO_WRITE = 'nothing_to_write';

    /** Not a refusal of the plan: a listing change left out of the token. */
    public const LISTING_NEEDS_APPROVAL = 'listing_needs_approval';

    public function __construct(public readonly string $reason, string $message, public readonly string $subject = '')
    {
        parent::__construct($message);
    }
}
