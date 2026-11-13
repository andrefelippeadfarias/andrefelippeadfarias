<?php

declare(strict_types=1);

namespace Ritmo\Domain\Report\Review;

/**
 * How a month's own sold occupancy compares with the market's.
 */
enum Reading: string
{
    /** The market sold too little yet to compare. */
    case Early = 'early';
    case Ahead = 'ahead';
    case OnPace = 'on_pace';
    case Behind = 'behind';
}
