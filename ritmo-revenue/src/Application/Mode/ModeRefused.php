<?php

declare(strict_types=1);

namespace Ritmo\Application\Mode;

use RuntimeException;

/**
 * A change of mode that is not allowed now, with a code and a sentence safe to show.
 */
final class ModeRefused extends RuntimeException
{
    public const NO_CAPABILITY = 'no_capability';
    public const CONDITIONS = 'conditions_not_met';
    public const DISABLED = 'autonomous_disabled';
    public const CONFIRMATION = 'confirmation_mismatch';

    public function __construct(public readonly string $reason, string $message)
    {
        parent::__construct($message);
    }
}
