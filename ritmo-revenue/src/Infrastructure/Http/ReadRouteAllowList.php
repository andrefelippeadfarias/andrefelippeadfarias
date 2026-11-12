<?php

declare(strict_types=1);

namespace Ritmo\Infrastructure\Http;

use InvalidArgumentException;

/**
 * The few routes that read data although they are not a GET (some APIs take a query in the
 * body of a POST). GuardedHttpClient lets a request other than GET through only when its
 * method, host and exact path are on this list.
 *
 * The list is built by the composition root from what each connector declares, and it is
 * empty unless someone adds to it: putting a route here is a product-safety decision. Only
 * POST can be listed; PUT, PATCH and DELETE are never reads. Paths are exact: no prefix, no
 * wildcard.
 */
final class ReadRouteAllowList
{
    private const HOST_PATTERN = '/^[a-z0-9]([a-z0-9.-]*[a-z0-9])?(:\d{1,5})?$/';
    private const PATH_PATTERN = '#^(/[A-Za-z0-9._~-]+)+/?$#';

    /**
     * @param array<string, array{connector: string, method: string, host: string, path: string}> $routes
     */
    private function __construct(private readonly array $routes = [])
    {
    }

    /**
     * The empty list: every request other than GET is refused.
     */
    public static function none(): self
    {
        return new self();
    }

    /**
     * A copy of the list with one more route.
     *
     * @param string $connector id of the connector that declares the route, for the diagnostics screen
     * @param string $method    "POST"
     * @param string $host      host in lowercase, with ":port" when it is not the default port
     * @param string $path      exact path, starting with "/"
     *
     * @throws InvalidArgumentException on any other method, or a host or path that is not exact
     */
    public function with(string $connector, string $method, string $host, string $path): self
    {
        if ($connector === '') {
            throw new InvalidArgumentException('A read route names the connector that declares it.');
        }
        if ($method !== 'POST') {
            throw new InvalidArgumentException(sprintf('Only a POST can be marked as a read; "%s" cannot.', $method));
        }
        if (preg_match(self::HOST_PATTERN, $host) !== 1) {
            throw new InvalidArgumentException(sprintf('"%s" is not an exact host.', $host));
        }
        if (preg_match(self::PATH_PATTERN, $path) !== 1 || str_contains($path, '..')) {
            throw new InvalidArgumentException(sprintf('"%s" is not an exact path.', $path));
        }

        $routes = $this->routes;
        $routes[self::key($method, $host, $path)] = ['connector' => $connector, 'method' => $method, 'host' => $host, 'path' => $path];

        return new self($routes);
    }

    public function allows(string $method, string $host, string $path): bool
    {
        return isset($this->routes[self::key($method, $host, $path)]);
    }

    /**
     * @return list<array{connector: string, method: string, host: string, path: string}>
     */
    public function routes(): array
    {
        return array_values($this->routes);
    }

    private static function key(string $method, string $host, string $path): string
    {
        return $method . ' ' . $host . $path;
    }
}
