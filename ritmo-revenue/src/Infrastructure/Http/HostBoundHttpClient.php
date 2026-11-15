<?php

declare(strict_types=1);

namespace Ritmo\Infrastructure\Http;

use Ritmo\Contracts\Exception\WriteBlocked;
use Ritmo\Contracts\HttpClient;
use Ritmo\Contracts\HttpResponse;
use Ritmo\Contracts\Logger;

/**
 * The hosts one channel of the plugin may reach, in front of the client it is given: the licence
 * server and the update server (each from its own constant of wp-config.php, never from a stored
 * setting). A request to any other host is refused, logged and never reaches the wrapped client.
 * An empty list lets nothing leave (a site with no server configured).
 */
final class HostBoundHttpClient implements HttpClient
{
    /**
     * @param list<string> $hosts exact hosts in lowercase, with ":port" when it is not the default (RequestTarget::fromUrl()->host)
     */
    public function __construct(
        private readonly HttpClient $inner,
        private readonly array $hosts,
        private readonly Logger $logger,
        private readonly string $channel,
    ) {
    }

    /**
     * The hosts of the given URLs (null entries skipped), as this client compares them.
     *
     * @param list<string|null> $urls
     *
     * @return list<string>
     */
    public static function hostsOf(array $urls): array
    {
        $hosts = [];
        foreach ($urls as $url) {
            if ($url !== null && $url !== '') {
                $hosts[] = RequestTarget::fromUrl($url)->host;
            }
        }

        return array_values(array_unique($hosts));
    }

    public function request(string $method, string $url, array $headers = [], ?string $body = null, int $timeout = 30): HttpResponse
    {
        $target = RequestTarget::fromUrl($url);
        if (!in_array($target->host, $this->hosts, true)) {
            $this->logger->warning('Blocked an outgoing request to a host this channel may not reach.', [
                'channel' => $this->channel,
                'method' => strtoupper($method),
                'host' => $target->host,
            ]);

            throw WriteBlocked::forRequest(strtoupper($method), (string) $target, sprintf('the %s channel may reach only its configured server', $this->channel));
        }

        return $this->inner->request($method, $url, $headers, $body, $timeout);
    }
}
