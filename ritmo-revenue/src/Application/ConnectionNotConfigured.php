<?php

declare(strict_types=1);

namespace Ritmo\Application;

use RuntimeException;

/**
 * A connector was needed but its credentials or settings are missing. The message is safe to show.
 */
final class ConnectionNotConfigured extends RuntimeException
{
    public static function forProvider(string $label): self
    {
        return new self(sprintf('The %s connection is not configured.', $label));
    }
}
