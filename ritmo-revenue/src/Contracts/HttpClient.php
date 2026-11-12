<?php

declare(strict_types=1);

namespace Ritmo\Contracts;

use Ritmo\Contracts\Exception\ConnectorException;
use Ritmo\Contracts\Exception\WriteBlocked;

/**
 * The only door to the network. Connectors never call the WordPress HTTP API or cURL directly,
 * so that rate limits, quotas, the read-only guard and the log apply to every request.
 */
interface HttpClient
{
    /**
     * Sends one request and returns the response whatever its status (4xx and 5xx are returned,
     * not thrown: the connector decides what they mean).
     *
     * @param string                $method  HTTP method in capitals ("GET", "POST", ...)
     * @param string                $url     absolute https URL
     * @param array<string, string> $headers header name => value
     * @param string|null           $body    raw request body, already encoded; null for none
     * @param int                   $timeout seconds
     *
     * @throws WriteBlocked       when the request would change an external system and writes are blocked
     * @throws ConnectorException when no response was received (DNS, TLS, timeout); retryable
     */
    public function request(string $method, string $url, array $headers = [], ?string $body = null, int $timeout = 30): HttpResponse;
}
