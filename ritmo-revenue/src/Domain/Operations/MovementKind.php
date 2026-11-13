<?php

declare(strict_types=1);

namespace Ritmo\Domain\Operations;

/**
 * What a booking read since the previous run is. "baseline" is the first read of a property: it
 * records what exists and moves nothing.
 *
 * Reference vocabulary (beds24.movimento_de): nova = new, alterada = modified, cancelada = cancelled.
 */
enum MovementKind: string
{
    case Baseline = 'baseline';
    case New = 'new';
    case Cancelled = 'cancelled';
    case Modified = 'modified';
}
