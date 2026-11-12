<?php

declare(strict_types=1);

namespace Ritmo\Tests\Unit\Infrastructure\Support;

use LogicException;
use Ritmo\Contracts\HttpClient;
use Ritmo\Contracts\HttpResponse;

/**
 * An HttpClient that answers from a script and remembers every request it was asked to send.
 */
final class ScriptedHttpClient implements HttpClient
{
    /** @var list<array{method: string, url: string, headers: array<string, string>, body: string|null, timeout: int}> */
    public array $requests = [];

    /** @var list<HttpResponse> */
    private array $responses;

    public function __construct(HttpResponse ...$responses)
    {
        $this->responses = array_values($responses);
    }

    public function request(string $method, string $url, array $headers = [], ?string $body = null, int $timeout = 30): HttpResponse
    {
        $this->requests[] = ['method' => $method, 'url' => $url, 'headers' => $headers, 'body' => $body, 'timeout' => $timeout];
        if ($this->responses === []) {
            throw new LogicException('The scripted client has no response left.');
        }

        return count($this->responses) === 1 ? $this->responses[0] : array_shift($this->responses);
    }
}
