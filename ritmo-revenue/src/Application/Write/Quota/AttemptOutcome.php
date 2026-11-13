<?php

declare(strict_types=1);

namespace Ritmo\Application\Write\Quota;

/**
 * The outcome of one recalculation attempt. An attempt is recorded as pending before the call
 * (write-ahead) and settled after it; a pending attempt older than the call timeout counts as a
 * failure, so a crash never under-counts.
 */
enum AttemptOutcome: string
{
    case Pending = 'pending';
    case Ok = 'ok';
    case Failure = 'failure';
    case RateLimited = 'rate_limited';
}
