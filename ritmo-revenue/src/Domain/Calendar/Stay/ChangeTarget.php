<?php

declare(strict_types=1);

namespace Ritmo\Domain\Calendar\Stay;

/**
 * The system a stay or arrival/departure change is meant for.
 */
enum ChangeTarget: string
{
    /** The property management system (inventory calendar). */
    case Pms = 'pms';

    /** The dynamic pricing system (date-specific override). */
    case Pricing = 'pricing';
}
