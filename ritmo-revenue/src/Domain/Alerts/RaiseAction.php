<?php

declare(strict_types=1);

namespace Ritmo\Domain\Alerts;

/**
 * What raising a draft does to the alerts stored under its key.
 */
enum RaiseAction: string
{
    /** No alert under the key, or a new episode of one that was resolved: a new row. */
    case Insert = 'insert';
    /** An open or acknowledged alert under the key: refreshed in place (no new row, no new e-mail). */
    case Refresh = 'refresh';
    /** A resolved event without newer evidence: nothing (a person already dealt with it). */
    case Ignore = 'ignore';
}
