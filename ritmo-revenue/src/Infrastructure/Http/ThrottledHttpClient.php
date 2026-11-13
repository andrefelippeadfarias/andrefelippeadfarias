<?php

declare(strict_types=1);

namespace Ritmo\Infrastructure\Http;

use Ritmo\Contracts\Exception\WriteBlocked;
use Ritmo\Contracts\HttpClient;
use Ritmo\Contracts\HttpResponse;
use Ritmo\Contracts\WriteHttpClient;
use Ritmo\Contracts\WriteToken;

/**
 * Makes every request of one connector wait for its turn in a RateLimiter before it is sent,
 * writes included (a write passes on to the wrapped client, which must be able to write).
 */
final class ThrottledHttpClient implements WriteHttpClient
{
    /**
     * @param string $key what the limits are counted for (the connector id, or one account of it)
     */
    public function __construct(
        private readonly HttpClient $inner,
        private readonly RateLimiter $limiter,
        private readonly string $key,
    ) {
    }

    public function request(string $method, string $url, array $headers = [], ?string $body = null, int $timeout = 30): HttpResponse
    {
        $this->limiter->acquire($this->key);

        return $this->inner->request($method, $url, $headers, $body, $timeout);
    }

    public function write(WriteToken $token, string $method, string $url, array $headers = [], ?string $body = null, int $timeout = 30): HttpResponse
    {
        if (!$this->inner instanceof WriteHttpClient) {
            throw WriteBlocked::forRequest($method, $url, 'the HTTP client of this connector is read-only');
        }
        $this->limiter->acquire($this->key);

        return $this->inner->write($token, $method, $url, $headers, $body, $timeout);
    }
}
