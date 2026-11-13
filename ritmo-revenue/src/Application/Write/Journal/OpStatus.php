<?php

declare(strict_types=1);

namespace Ritmo\Application\Write\Journal;

/**
 * The status of one step in the write journal.
 */
enum OpStatus: string
{
    /** In the schedule, not sent yet (inserted before the first call of the run). */
    case Planned = 'planned';
    /** Committed right before its call: if it stays so, the process died mid-call. */
    case Sending = 'sending';
    /** The system took the call (2xx). */
    case Accepted = 'accepted';
    /** Read back as intended. */
    case Stored = 'stored';
    /** Refused (400, 403...): nothing of the call was stored. */
    case Rejected = 'rejected';
    /** Refused because the pricing system has no data yet for a date. */
    case NotReady = 'not_ready';
    case RateLimited = 'rate_limited';
    /** No answer, a 5xx or a partial refusal: it may or may not have applied; a later run reads it back. */
    case Unknown = 'unknown';
    /** Read back different from what was intended. */
    case Mismatch = 'mismatch';
    /** Not sent on purpose (changed since read, pricing first, no recalculation left). */
    case Skipped = 'skipped';
    /** Not sent because the run stopped before it. */
    case Abandoned = 'abandoned';
    /** The same body was refused too many runs in a row: not sent until someone looks. */
    case Quarantined = 'quarantined';
}
