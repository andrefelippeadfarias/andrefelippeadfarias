<?php

declare(strict_types=1);

namespace Ritmo\Domain\Plan;

/**
 * The listing amounts a plan may change. Nothing else of the listing (channel discounts, offers,
 * visibility programmes) is ever part of a plan.
 *
 * Reference: validar_plano.Validador.validar_anuncio (campo base, min or max).
 */
enum ListingField: string
{
    case Base = 'base';
    case Min = 'min';
    case Max = 'max';
}
