<?php

declare(strict_types=1);

namespace Ritmo\Infrastructure\Http;

use InvalidArgumentException;

/**
 * The exact routes a write run may send, each one a method, a host and a path pattern where
 * "{id}" stands for one path segment (letters, digits and "._~-"). GuardedHttpClient lets a
 * write through only when it is on this list AND carries a live write token; every other
 * request that is not a GET stays blocked.
 *
 * The list is empty unless the composition root of a write run fills it from what the connectors
 * declare. Booking routes are never put here.
 */
final class WriteRouteAllowList
{
    private const METHODS = ['POST', 'PUT', 'DELETE'];
    private const HOST_PATTERN = '/^[a-z0-9]([a-z0-9.-]*[a-z0-9])?(:\d{1,5})?$/';
    private const PATTERN_PATTERN = '#^(/([A-Za-z0-9._~-]+|\{id\}))+$#';
    private const SEGMENT = '[A-Za-z0-9._~-]+';

    /**
     * @param array<string, array{connector: string, method: string, host: string, pattern: string}> $routes
     */
    private function __construct(private readonly array $routes = [])
    {
    }

    public static function none(): self
    {
        return new self();
    }

    /**
     * A copy of the list with one more route.
     *
     * @param string $pattern exact path with "{id}" placeholders, e.g. "/v1/listings/{id}/overrides"
     *
     * @throws InvalidArgumentException on another method, a host that is not exact or a malformed pattern
     */
    public function with(string $connector, string $method, string $host, string $pattern): self
    {
        if ($connector === '') {
            throw new InvalidArgumentException('A write route names the connector that declares it.');
        }
        if (!in_array($method, self::METHODS, true)) {
            throw new InvalidArgumentException(sprintf('"%s" is not a write method.', $method));
        }
        if (preg_match(self::HOST_PATTERN, $host) !== 1) {
            throw new InvalidArgumentException(sprintf('"%s" is not an exact host.', $host));
        }
        if (preg_match(self::PATTERN_PATTERN, $pattern) !== 1 || str_contains($pattern, '..') || str_contains($pattern, '/booking')) {
            throw new InvalidArgumentException(sprintf('"%s" is not a write route the plugin may declare.', $pattern));
        }

        $routes = $this->routes;
        $routes[$method . ' ' . $host . $pattern] = ['connector' => $connector, 'method' => $method, 'host' => $host, 'pattern' => $pattern];

        return new self($routes);
    }

    /**
     * The same, from a connector's base URL and a path relative to it.
     *
     * @throws InvalidArgumentException as with()
     */
    public function withBase(string $connector, string $method, string $baseUrl, string $relativePattern): self
    {
        $parts = parse_url($baseUrl);
        if (!is_array($parts) || !isset($parts['host'])) {
            throw new InvalidArgumentException('The base URL of a write route has no host.');
        }
        $host = strtolower($parts['host']) . (isset($parts['port']) ? ':' . $parts['port'] : '');

        return $this->with($connector, $method, $host, rtrim($parts['path'] ?? '', '/') . $relativePattern);
    }

    public function allows(string $method, string $host, string $path): bool
    {
        if (str_contains($path, '..')) {
            return false;
        }
        foreach ($this->routes as $route) {
            if ($route['method'] === $method && $route['host'] === $host && preg_match(self::regex($route['pattern']), $path) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<array{connector: string, method: string, host: string, pattern: string}>
     */
    public function routes(): array
    {
        return array_values($this->routes);
    }

    private static function regex(string $pattern): string
    {
        $parts = array_map(static fn (string $part): string => $part === '{id}' ? self::SEGMENT : preg_quote($part, '#'), explode('/', $pattern));

        return '#^' . implode('/', $parts) . '$#';
    }
}
