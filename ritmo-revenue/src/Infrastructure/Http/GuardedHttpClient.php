<?php

declare(strict_types=1);

namespace Ritmo\Infrastructure\Http;

use Ritmo\Contracts\Exception\WriteBlocked;
use Ritmo\Contracts\HttpClient;
use Ritmo\Contracts\HttpResponse;
use Ritmo\Contracts\Logger;
use Ritmo\Contracts\WriteAuthority;
use Ritmo\Contracts\WriteHttpClient;
use Ritmo\Contracts\WriteToken;

/**
 * The read-only guard of the plugin, placed in front of the real HTTP client.
 *
 * Only GET leaves the site. Any other method, to any host, is refused with WriteBlocked
 * before the wrapped client is called, unless its method, host and exact path are on the
 * list of routes marked as reads. A request that tries to change its method through an
 * override header is refused too. Every refusal is logged and kept in blockedAttempts().
 *
 * write() is the only door for a request that changes an external system: its route must be on
 * the write allow-list and its token live in the write authority (the write gate's registry).
 * A client built without them (every read-only run) refuses every write.
 */
final class GuardedHttpClient implements WriteHttpClient
{
    private const OVERRIDE_HEADERS = ['x-http-method-override', 'x-http-method', 'x-method-override'];
    private const REASON_READ_ONLY = 'the plugin is read-only and this route is not marked as a read';
    private const REASON_OVERRIDE = 'the request carries a method-override header';
    private const REASON_NOT_A_WRITE_ROUTE = 'the route is not a write route of the plugin';
    private const REASON_NO_AUTHORITY = 'this client was not built for a write run';
    private const REASON_TOKEN = 'the write token is not live';

    /** @var list<array{method: string, host: string, path: string, reason: string}> */
    private array $blocked = [];

    public function __construct(
        private readonly HttpClient $inner,
        private readonly ReadRouteAllowList $readRoutes,
        private readonly Logger $logger,
        private readonly ?WriteRouteAllowList $writeRoutes = null,
        private readonly ?WriteAuthority $authority = null,
    ) {
    }

    public function request(string $method, string $url, array $headers = [], ?string $body = null, int $timeout = 30): HttpResponse
    {
        $method = RequestLine::method($method);
        $target = RequestTarget::fromUrl($url);
        RequestLine::headerLines($headers);

        $this->refuse($method, $target, $this->refusal($method, $target, $headers));

        return $this->inner->request($method, $url, $headers, $body, $timeout);
    }

    public function write(WriteToken $token, string $method, string $url, array $headers = [], ?string $body = null, int $timeout = 30): HttpResponse
    {
        $method = RequestLine::method($method);
        $target = RequestTarget::fromUrl($url);
        RequestLine::headerLines($headers);

        $this->refuse($method, $target, $this->writeRefusal($token, $method, $target, $headers));

        return $this->inner->request($method, $url, $headers, $body, $timeout);
    }

    /**
     * The requests refused by this client since it was created.
     *
     * @return list<array{method: string, host: string, path: string, reason: string}>
     */
    public function blockedAttempts(): array
    {
        return $this->blocked;
    }

    /**
     * @throws WriteBlocked when there is a reason
     */
    private function refuse(string $method, RequestTarget $target, ?string $reason): void
    {
        if ($reason === null) {
            return;
        }
        $this->blocked[] = ['method' => $method, 'host' => $target->host, 'path' => $target->path, 'reason' => $reason];
        $this->logger->warning('Blocked an outgoing request that could change an external system.', [
            'method' => $method,
            'host' => $target->host,
            'path' => $target->path,
            'reason' => $reason,
        ]);

        throw WriteBlocked::forRequest($method, (string) $target, $reason);
    }

    /**
     * Why the write may not leave the site; null when it may.
     *
     * @param array<array-key, mixed> $headers
     */
    private function writeRefusal(WriteToken $token, string $method, RequestTarget $target, array $headers): ?string
    {
        if (self::overridesMethod($headers)) {
            return self::REASON_OVERRIDE;
        }
        if ($this->writeRoutes === null || !$this->writeRoutes->allows($method, $target->host, $target->path)) {
            return self::REASON_NOT_A_WRITE_ROUTE;
        }
        if ($this->authority === null) {
            return self::REASON_NO_AUTHORITY;
        }

        return $this->authority->isLive($token) ? null : self::REASON_TOKEN;
    }

    /**
     * @param array<array-key, mixed> $headers
     */
    private static function overridesMethod(array $headers): bool
    {
        foreach (array_keys($headers) as $name) {
            if (in_array(strtolower(trim((string) $name)), self::OVERRIDE_HEADERS, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Why the request may not leave the site; null when it may.
     *
     * @param array<array-key, mixed> $headers
     */
    private function refusal(string $method, RequestTarget $target, array $headers): ?string
    {
        if (self::overridesMethod($headers)) {
            return self::REASON_OVERRIDE;
        }

        if ($method === 'GET' || $this->readRoutes->allows($method, $target->host, $target->path)) {
            return null;
        }

        return self::REASON_READ_ONLY;
    }
}
