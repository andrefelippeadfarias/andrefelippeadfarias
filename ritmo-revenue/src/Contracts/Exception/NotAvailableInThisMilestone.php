<?php

declare(strict_types=1);

namespace Ritmo\Contracts\Exception;

use LogicException;

/**
 * Thrown by every write method of a connector while the plugin is read-only (milestone M1).
 *
 * It is a LogicException on purpose: reaching it means a code path tried to write when no such
 * path may exist yet, which is a bug and not an external failure to retry or swallow.
 */
final class NotAvailableInThisMilestone extends LogicException
{
    public static function forOperation(string $operation): self
    {
        return new self(sprintf('"%s" is not available in this milestone: the plugin is read-only.', $operation));
    }
}
