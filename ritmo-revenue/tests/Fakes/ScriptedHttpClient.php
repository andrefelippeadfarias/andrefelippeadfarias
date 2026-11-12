<?php

declare(strict_types=1);

namespace Ritmo\Tests\Fakes;

use Closure;
use LogicException;
use Ritmo\Contracts\HttpClient;
use Ritmo\Contracts\HttpResponse;
use Throwable;

/**
 * An HttpClient that answers from a script and records every request it was asked to send.
 *
 * Replies are taken from the queue first; when it is empty the handler (if any) answers.
 * A queued Throwable is thrown instead of answering, as a transport failure would be.
 */
final class ScriptedHttpClient implements HttpClient
{
    /** @var list<HttpResponse|Throwable> */
    private array $queue = [];

    /** @var list<array{method: string, url: string, path: string, query: array<string, list<string>>, headers: array<string, string>, body: ?string, timeout: int}> */
    private array $requests = [];

    private ?Closure $handler;

    /**
     * @param (callable(string, string, array<string, string>, ?string): HttpResponse)|null $handler
     */
    public function __construct(?callable $handler = null)
    {
        $this->handler = $handler === null ? null : Closure::fromCallable($handler);
    }

    /**
     * A JSON response, as an API would send it.
     *
     * @param array<int|string, mixed> $data
     * @param array<string, string>    $headers
     */
    public static function json(int $status, array $data, array $headers = []): HttpResponse
    {
        return new HttpResponse($status, ['Content-Type' => 'application/json'] + $headers, json_encode($data, JSON_THROW_ON_ERROR));
    }

    public function queue(HttpResponse|Throwable ...$replies): self
    {
        array_push($this->queue, ...$replies);

        return $this;
    }

    public function request(string $method, string $url, array $headers = [], ?string $body = null, int $timeout = 30): HttpResponse
    {
        $this->requests[] = [
            'method' => $method,
            'url' => $url,
            'path' => (string) parse_url($url, PHP_URL_PATH),
            'query' => self::query((string) parse_url($url, PHP_URL_QUERY)),
            'headers' => $headers,
            'body' => $body,
            'timeout' => $timeout,
        ];

        $reply = array_shift($this->queue);
        if ($reply === null && $this->handler !== null) {
            $reply = ($this->handler)($method, $url, $headers, $body);
        }
        if ($reply === null) {
            throw new LogicException(sprintf('No scripted reply for %s %s.', $method, $url));
        }
        if ($reply instanceof Throwable) {
            throw $reply;
        }

        return $reply;
    }

    /**
     * @return list<array{method: string, url: string, path: string, query: array<string, list<string>>, headers: array<string, string>, body: ?string, timeout: int}>
     */
    public function requests(): array
    {
        return $this->requests;
    }

    /**
     * "METHOD /path" of every request, in order.
     *
     * @return list<string>
     */
    public function lines(): array
    {
        return array_map(static fn (array $request): string => $request['method'] . ' ' . $request['path'], $this->requests);
    }

    /**
     * Replies still queued (a test that scripts more than was asked for left something unproven).
     */
    public function pending(): int
    {
        return count($this->queue);
    }

    /**
     * A query string where a repeated name is a list (status=a&status=b).
     *
     * @return array<string, list<string>>
     */
    public static function query(string $queryString): array
    {
        $query = [];
        foreach (explode('&', $queryString) as $pair) {
            if ($pair === '') {
                continue;
            }
            [$name, $value] = array_pad(explode('=', $pair, 2), 2, '');
            $query[rawurldecode($name)][] = rawurldecode($value);
        }

        return $query;
    }
}
