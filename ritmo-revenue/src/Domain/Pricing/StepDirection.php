<?php

declare(strict_types=1);

namespace Ritmo\Domain\Pricing;

/**
 * Which way a pace step moves the price of a night.
 */
enum StepDirection: string
{
    case Up = 'up';
    case Down = 'down';
}
