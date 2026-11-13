<?php

declare(strict_types=1);

namespace Ritmo\Tests\Integration\Run\Support;

use Closure;
use Ritmo\Contracts\HttpClient;
use Ritmo\Contracts\HttpResponse;
use Ritmo\Tests\Integration\Write\Support\InProcessApis;

/**
 * The in-process simulator with a hook called after every write request it answered (the
 * calendar read excluded): a test engages the emergency switch between two slices with it.
 */
final class HookedApis implements HttpClient
{
    /** @var (Closure(int): void)|null called with the number of writes answered so far */
    public ?Closure $afterWrite = null;

    private int $writes = 0;

    public function __construct(private readonly InProcessApis $apis)
    {
    }

    public function request(string $method, string $url, array $headers = [], ?string $body = null, int $timeout = 30): HttpResponse
    {
        $response = $this->apis->request($method, $url, $headers, $body, $timeout);
        if ($method !== 'GET' && !str_ends_with($url, '/listing_prices') && $this->afterWrite !== null) {
            ($this->afterWrite)(++$this->writes);
        }

        return $response;
    }
}
