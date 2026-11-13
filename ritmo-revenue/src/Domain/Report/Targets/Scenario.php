<?php

declare(strict_types=1);

namespace Ritmo\Domain\Report\Targets;

/**
 * The three forecast scenarios.
 */
enum Scenario: string
{
    case Floor = 'floor';
    case Base = 'base';
    case Goal = 'goal';
}
