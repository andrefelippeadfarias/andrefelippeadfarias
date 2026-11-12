<?php

declare(strict_types=1);

namespace Ritmo\Domain\Relations;

/**
 * How the chain relations (at least, between, premium) treat a room closed in the PMS without a
 * reservation. The discount band and the occupancy switch always exclude it.
 */
enum BlockedNights: string
{
    /** Neither adjusted nor used as a reference. */
    case Exclude = 'exclude';

    /** Only sold-out rooms leave the comparison. */
    case Ignore = 'ignore';
}
