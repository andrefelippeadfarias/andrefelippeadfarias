<?php

declare(strict_types=1);

namespace Ritmo\Domain\Model;

/**
 * How far a property lets the plugin act.
 */
enum PropertyMode: string
{
    /** Computes and shows what it would write; never writes. The default. */
    case Shadow = 'shadow';

    /** Writes only what a person approved. */
    case Approval = 'approval';

    /** Writes on its own, inside the safety limits. An explicit opt-in. */
    case Auto = 'auto';
}
