<?php

declare(strict_types=1);

namespace Ritmo\Domain\Relations;

/**
 * Which nights the coherence stage checks for a relation.
 */
enum ScanMode: string
{
    /** Only nights on which the engine decided a price for a room of the relation's group in this run. */
    case Touched = 'touched';

    /** Every night of the scan horizon (full run) or of the light-run filter. */
    case Horizon = 'horizon';
}
