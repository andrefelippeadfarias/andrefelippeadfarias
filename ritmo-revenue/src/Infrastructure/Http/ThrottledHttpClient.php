<?php

declare(strict_types=1);

namespace Ritmo\Infrastructure\Http;

use Ritmo\Contracts\HttpClient;
use Ritmo\Contracts\HttpResponse;

/**
 * Makes every request of one connector wait for its turn in a RateLimiter before it is sent.
 */
final class ThrottledHttpClient implements HttpClient
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
}
