<?php

declare(strict_types=1);

namespace Ritmo\Domain\Alerts;

/**
 * Where an alert stands. Acknowledged means a person saw it; it stays listed until resolved.
 */
enum AlertStatus: string
{
    case Open = 'open';
    case Acknowledged = 'acknowledged';
    case Resolved = 'resolved';

    public function isActive(): bool
    {
        return $this !== self::Resolved;
    }
}
