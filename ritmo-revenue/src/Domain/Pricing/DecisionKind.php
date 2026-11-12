<?php

declare(strict_types=1);

namespace Ritmo\Domain\Pricing;

/**
 * What the pace engine concluded for a room night.
 */
enum DecisionKind: string
{
    /** A new floor (and maybe ceiling) is proposed. */
    case Step = 'step';

    /** The engine looked at the night and proposes nothing now. */
    case Hold = 'hold';

    /** The night is outside what the engine decides. */
    case Skipped = 'skipped';
}
