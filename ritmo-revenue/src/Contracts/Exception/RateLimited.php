<?php

declare(strict_types=1);

namespace Ritmo\Contracts\Exception;

use Throwable;

/**
 * The external system refused the request because a rate limit or a quota was reached
 * (HTTP 429 or the provider's own signal). Callers stop the current batch; they do not loop.
 */
final class RateLimited extends ConnectorException
{
    /**
     * @param int|null $retryAfterSeconds seconds until the limit resets, when the provider says so
     */
    public function __construct(
        string $message,
        string $provider = '',
        private readonly ?int $retryAfterSeconds = null,
        ?int $httpStatus = 429,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $provider, $httpStatus, true, $previous);
    }

    public function retryAfterSeconds(): ?int
    {
        return $this->retryAfterSeconds;
    }
}
