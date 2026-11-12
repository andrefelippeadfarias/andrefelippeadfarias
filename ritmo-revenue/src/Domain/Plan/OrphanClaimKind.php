<?php

declare(strict_types=1);

namespace Ritmo\Domain\Plan;

/**
 * The kinds of orphan night an item may claim.
 *
 * Reference: "orfa" of validar_plano.py: "seguinte" (NextNight), "sábado" (ClosedDayArrival),
 * "sexta" (ClosedDayDeparture).
 */
enum OrphanClaimKind: string
{
    /** A free night whose next night is sold out: an arrival there fits one night only. */
    case NextNight = 'next_night';

    /** A free closed day whose previous night is sold out (or past): it opens for arrival and one night. */
    case ClosedDayArrival = 'closed_day_arrival';

    /** A sold-out closed day whose previous night is free: its departure opens, so that night sells alone. */
    case ClosedDayDeparture = 'closed_day_departure';
}
