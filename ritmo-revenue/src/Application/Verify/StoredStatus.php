<?php

declare(strict_types=1);

namespace Ritmo\Application\Verify;

/**
 * What the read-back of a written night showed.
 */
enum StoredStatus: string
{
    /** The night holds what the write should have left: the change log and the ledger record it. */
    case Stored = 'stored';

    /** The night holds something else: an alert, never a rewrite in the same run. */
    case Mismatch = 'mismatch';

    /** The read-back failed: nothing is recorded; the next run's resume reads it again. */
    case Unread = 'unread';
}
