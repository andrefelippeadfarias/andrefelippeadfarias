<?php

declare(strict_types=1);

namespace Ritmo\Contracts;

use Ritmo\Contracts\Exception\ConnectorException;
use Ritmo\Contracts\Exception\WriteBlocked;

/**
 * An HTTP client that can also send a request that changes an external system, under a live
 * write token. request() stays read-only: a write route sent through it is refused.
 */
interface WriteHttpClient extends HttpClient
{
    /**
     * Sends one write request and returns the response whatever its status.
     *
     * @param array<string, string> $headers header name => value
     *
     * @throws WriteBlocked       when the route is not a declared write route or the token is not live
     * @throws ConnectorException when no response was received (timeout, network); the outcome is unknown
     */
    public function write(WriteToken $token, string $method, string $url, array $headers = [], ?string $body = null, int $timeout = 30): HttpResponse;
}
