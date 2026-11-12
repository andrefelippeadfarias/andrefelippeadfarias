<?php

declare(strict_types=1);

namespace Ritmo\Domain\Model;

/**
 * Where the sales of a night stand against its expected pace curve.
 */
enum PaceStatus: string
{
    case Ahead = 'ahead';
    case OnPace = 'on_pace';
    case Behind = 'behind';
    case VeryBehind = 'very_behind';
}
