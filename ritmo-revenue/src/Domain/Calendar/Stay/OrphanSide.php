<?php

declare(strict_types=1);

namespace Ritmo\Domain\Calendar\Stay;

use Ritmo\Domain\Model\Restriction;

/**
 * The orphan side of a closed day: when the closed day is sold out and the night before is free,
 * the closed day takes whenClosedDaySoldOut (departure opens, so the night before sells alone);
 * when the night before is sold out (or already past) and the closed day is free, it takes
 * whenPreviousSoldOut (arrival opens) and sells with one night.
 *
 * Reference: parametros.regra_h_beds24.orfas.
 */
final class OrphanSide
{
    public function __construct(
        public readonly bool $enabled = false,
        public readonly bool $onHolidays = false,
        public readonly Restriction $whenClosedDaySoldOut = Restriction::NoCheckin,
        public readonly Restriction $whenPreviousSoldOut = Restriction::NoCheckout,
    ) {
    }
}
