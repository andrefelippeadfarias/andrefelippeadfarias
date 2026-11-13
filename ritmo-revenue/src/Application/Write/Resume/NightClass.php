<?php

declare(strict_types=1);

namespace Ritmo\Application\Write\Resume;

/**
 * What the re-read says of one night of an unsettled step.
 */
enum NightClass: string
{
    /** The stored state holds what the step intended (a deletion: no rule). */
    case Applied = 'applied';
    /** The stored state is still the one read before the step. */
    case NotApplied = 'not_applied';
    /** Neither: someone else changed it, or the call applied in part. */
    case Diverged = 'diverged';
}
