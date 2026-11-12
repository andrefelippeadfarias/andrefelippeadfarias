<?php

declare(strict_types=1);

namespace Ritmo\Domain\Calendar\Stay;

/**
 * Why a night may sell with a one-night stay below its rule.
 */
enum OrphanKind: string
{
    /** The night is free and the next night is sold out: an arrival there fits only one night. */
    case NextNight = 'next_night';

    /** A closed day is free and the night before it is sold out (or already past): it opens for arrival. */
    case ClosedDay = 'closed_day';
}
