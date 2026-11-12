<?php

declare(strict_types=1);

namespace Ritmo\Domain\Calendar\Stay;

/**
 * The orphan night of the arrival rule: a free night whose next night is sold out sells with one
 * night (never in a package; in holiday periods only when onHolidays).
 *
 * Reference: parametros.estadia_por_dia_chegada.orfa_noite_seguinte and orfa_em_feriado.
 */
final class OrphanPolicy
{
    public function __construct(
        public readonly bool $nextNight = false,
        public readonly bool $onHolidays = false,
    ) {
    }
}
