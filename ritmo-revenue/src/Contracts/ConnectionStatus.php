<?php

declare(strict_types=1);

namespace Ritmo\Contracts;

/**
 * Result of a connector's testConnection(): safe to show on the connections screen.
 */
final class ConnectionStatus
{
    /**
     * @param string                                 $message short text for the screen; never a credential
     * @param array<string, bool|int|float|string|null> $details facts read during the test (account label, listing count)
     */
    public function __construct(
        public readonly bool $ok,
        public readonly string $message,
        public readonly array $details = [],
    ) {
    }
}
