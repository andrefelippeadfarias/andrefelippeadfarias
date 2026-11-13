<?php

declare(strict_types=1);

namespace Ritmo\Domain\Alerts;

/**
 * How an alert reaches people besides the dashboard.
 */
enum Delivery: string
{
    case Immediate = 'immediate';
    case Digest = 'digest';
    case None = 'none';
}
