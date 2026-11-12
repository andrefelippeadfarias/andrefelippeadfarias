<?php

declare(strict_types=1);

namespace Ritmo\Contracts\Exception;

use RuntimeException;

/**
 * A request that would change an external system was stopped before leaving the site: the
 * plugin is read-only, the property is in shadow mode, the emergency switch is on or the
 * request carries no valid write token.
 *
 * It does not extend ConnectorException, so retry logic can never treat it as transient.
 */
final class WriteBlocked extends RuntimeException
{
    public static function forRequest(string $method, string $target, string $why): self
    {
        return new self(sprintf('Blocked %s %s: %s.', strtoupper($method), $target, $why));
    }
}
