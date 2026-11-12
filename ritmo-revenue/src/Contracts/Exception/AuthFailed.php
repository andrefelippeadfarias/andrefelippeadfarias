<?php

declare(strict_types=1);

namespace Ritmo\Contracts\Exception;

use Throwable;

/**
 * The external system rejected the credentials (HTTP 401/403 or the provider's own signal).
 * Never retried automatically: a person has to fix the key.
 */
final class AuthFailed extends ConnectorException
{
    public function __construct(
        string $message,
        string $provider = '',
        ?int $httpStatus = 401,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $provider, $httpStatus, false, $previous);
    }
}
