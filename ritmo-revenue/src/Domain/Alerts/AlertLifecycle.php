<?php

declare(strict_types=1);

namespace Ritmo\Domain\Alerts;

/**
 * How an alert ends. An event (a booking from an unknown origin, a write that failed) is resolved
 * by a person; a condition (an overbooked night, a connector down) resolves itself when a later
 * run that checked the same scope no longer finds it.
 */
enum AlertLifecycle: string
{
    case Event = 'event';
    case Condition = 'condition';
}
