<?php

declare(strict_types=1);

namespace Ritmo\Infrastructure\Http;

use Ritmo\Contracts\Exception\WriteBlocked;
use Ritmo\Contracts\HttpClient;
use Ritmo\Contracts\HttpResponse;
use Ritmo\Contracts\Logger;

/**
 * The read-only guard of the plugin, placed in front of the real HTTP client.
 *
 * Only GET leaves the site. Any other method, to any host, is refused with WriteBlocked
 * before the wrapped client is called, unless its method, host and exact path are on the
 * list of routes marked as reads. A request that tries to change its method through an
 * override header is refused too. Every refusal is logged and kept in blockedAttempts().
 */
final class GuardedHttpClient implements HttpClient
{
    private const OVERRIDE_HEADERS = ['x-http-method-override', 'x-http-method', 'x-method-override'];
    private const REASON_READ_ONLY = 'the plugin is read-only and this route is not marked as a read';
    private const REASON_OVERRIDE = 'the request carries a method-override header';

    /** @var list<array{method: string, host: string, path: string, reason: string}> */
    private array $blocked = [];

    public function __construct(
        private readonly HttpClient $inner,
        private readonly ReadRouteAllowList $readRoutes,
        private readonly Logger $logger,
    ) {
    }

    public function request(string $method, string $url, array $headers = [], ?string $body = null, int $timeout = 30): HttpResponse
    {
        $method = RequestLine::method($method);
        $target = RequestTarget::fromUrl($url);
        RequestLine::headerLines($headers);

        $reason = $this->refusal($method, $target, $headers);
        if ($reason !== null) {
            $this->blocked[] = ['method' => $method, 'host' => $target->host, 'path' => $target->path, 'reason' => $reason];
            $this->logger->warning('Blocked an outgoing request that could change an external system.', [
                'method' => $method,
                'host' => $target->host,
                'path' => $target->path,
                'reason' => $reason,
            ]);

            throw WriteBlocked::forRequest($method, (string) $target, $reason);
        }

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
     * Why the request may not leave the site; null when it may.
     *
     * @param array<array-key, mixed> $headers
     */
    private function refusal(string $method, RequestTarget $target, array $headers): ?string
    {
        foreach (array_keys($headers) as $name) {
            if (in_array(strtolower(trim((string) $name)), self::OVERRIDE_HEADERS, true)) {
                return self::REASON_OVERRIDE;
            }
        }

        if ($method === 'GET' || $this->readRoutes->allows($method, $target->host, $target->path)) {
            return null;
        }

        return self::REASON_READ_ONLY;
    }
}
