<?php

declare(strict_types=1);

namespace Ritmo\Domain\Relations;

/**
 * Which rooms of a set (or which references) a relation compares with.
 */
enum MemberPolicy: string
{
    /** A sold-out room leaves the comparison. */
    case FreeOnly = 'free_only';

    case All = 'all';
}
