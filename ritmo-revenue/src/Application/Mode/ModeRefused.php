<?php

declare(strict_types=1);

namespace Ritmo\Application\Mode;

use RuntimeException;

/**
 * A change of mode that is not allowed now, with a code, a sentence safe to show and, for unmet
 * conditions, each condition with its code and values (the screens translate them).
 */
final class ModeRefused extends RuntimeException
{
    public const NO_CAPABILITY = 'no_capability';
    public const CONDITIONS = 'conditions_not_met';
    public const DISABLED = 'autonomous_disabled';
    public const CONFIRMATION = 'confirmation_mismatch';

    /**
     * @param list<array{code: string, params: array<string, int|string>, text: string}> $conditions
     */
    public function __construct(public readonly string $reason, string $message, public readonly array $conditions = [])
    {
        parent::__construct($message);
    }
}
