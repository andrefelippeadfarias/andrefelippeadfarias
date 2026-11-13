<?php

declare(strict_types=1);

namespace Ritmo\Application\Write\Execute;

/**
 * Why a write run sent no further write.
 */
enum StopReason: string
{
    case LockLost = 'lock_lost';
    case KillSwitch = 'kill_switch';
    case DayTurned = 'day_turned';
    case TokenExpired = 'token_expired';
    case TimeBudget = 'time_budget';
    case Rejected = 'rejected';
    case NotReady = 'not_ready';
    case RateLimited = 'rate_limited';
    case AuthFailed = 'auth_failed';
    case UpstreamError = 'upstream_error';
    case Unknown = 'unknown';
    case Blocked = 'blocked';
    case ReadFailed = 'read_failed';
}
