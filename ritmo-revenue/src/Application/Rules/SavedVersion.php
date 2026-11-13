<?php

declare(strict_types=1);

namespace Ritmo\Application\Rules;

/**
 * What saving rules did: the version now in force, the one before it, how many values changed
 * and how many requests waiting for approval were closed. Unchanged when the rules equal the
 * version in force (nothing was saved).
 */
final class SavedVersion
{
    public function __construct(
        public readonly int $version,
        public readonly ?int $previous,
        public readonly int $changes,
        public readonly int $closedApprovals,
        public readonly bool $unchanged,
    ) {
    }
}
