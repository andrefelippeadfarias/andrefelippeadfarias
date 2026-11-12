<?php

declare(strict_types=1);

namespace Ritmo\Domain\Relations;

/**
 * What the currency re-check does after the floors were turned into whole percents.
 */
enum RecheckMode: string
{
    case Off = 'off';

    /** Report the broken relations only. */
    case Warn = 'warn';

    /** Move the dependent room by whole percent steps and report. */
    case Fix = 'fix';
}
