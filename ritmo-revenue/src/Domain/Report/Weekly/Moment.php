<?php

declare(strict_types=1);

namespace Ritmo\Domain\Report\Weekly;

/**
 * Where a stay week sits against today.
 */
enum Moment: string
{
    case Past = 'past';
    case Current = 'current';
    case Future = 'future';
}
