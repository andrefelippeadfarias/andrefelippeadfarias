<?php

declare(strict_types=1);

namespace Ritmo\Contracts\Exception;

use RuntimeException;
use Throwable;

/**
 * Base of every failure talking to an external system (PMS, pricing system, market data,
 * licence server, AI provider).
 *
 * Messages are written for the log and the diagnostics screen: they must never contain a
 * credential, a full request with headers or any guest data.
 */
class ConnectorException extends RuntimeException
{
    /**
     * @param string   $provider   id of the connector or provider that failed ("beds24", "pricelabs")
     * @param int|null $httpStatus HTTP status of the response, when there was one
     * @param bool     $retryable  true when the same request may succeed later (timeouts, 5xx)
     */
    public function __construct(
        string $message,
        private readonly string $provider = '',
        private readonly ?int $httpStatus = null,
        private readonly bool $retryable = false,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public function provider(): string
    {
        return $this->provider;
    }

    public function httpStatus(): ?int
    {
        return $this->httpStatus;
    }

    public function isRetryable(): bool
    {
        return $this->retryable;
    }
}
