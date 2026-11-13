<?php

declare(strict_types=1);

namespace Ritmo\Domain\Alerts;

/**
 * How urgent an alert is. Critical alerts are e-mailed at once, warnings in the daily digest,
 * information never.
 */
enum AlertSeverity: string
{
    case Critical = 'critical';
    case Warning = 'warning';
    case Info = 'info';
}
