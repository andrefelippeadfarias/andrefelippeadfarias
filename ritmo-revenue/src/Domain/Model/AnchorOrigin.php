<?php

declare(strict_types=1);

namespace Ritmo\Domain\Model;

/**
 * Where the market anchor of a night came from.
 */
enum AnchorOrigin: string
{
    /** Sampled for that very night. */
    case Measured = 'measured';

    /** No sample for the night: the median of the same night type was used instead. */
    case Fallback = 'fallback';
}
