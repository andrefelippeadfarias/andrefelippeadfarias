<?php

declare(strict_types=1);

namespace Ritmo\Infrastructure\Http;

use Ritmo\Contracts\Exception\ConnectorException;
use Ritmo\Contracts\HttpClient;
use Ritmo\Contracts\HttpResponse;

/**
 * HttpClient over the WordPress HTTP API (wp_remote_request), so the site's proxy and
 * certificate settings apply.
 *
 * It sends exactly one request: redirects are not followed, so a request never changes host
 * or method on the way. It has no guard of its own; wrap it in GuardedHttpClient.
 */
final class WpHttpClient implements HttpClient
{
    public function __construct(private readonly string $userAgent = 'RitmoRevenue')
    {
    }

    public function request(string $method, string $url, array $headers = [], ?string $body = null, int $timeout = 30): HttpResponse
    {
        $method = RequestLine::method($method);
        $target = RequestTarget::fromUrl($url);
        RequestLine::headerLines($headers);

        $response = wp_remote_request($url, [
            'method' => $method,
            'headers' => $headers,
            'body' => $body,
            'timeout' => max(1, $timeout),
            'redirection' => 0,
            'user-agent' => $this->userAgent,
        ]);
        if (is_wp_error($response)) {
            throw new ConnectorException(sprintf('No response from %s: %s.', $target->host, rtrim((string) $response->get_error_message(), '.')), '', null, true);
        }

        $responseHeaders = [];
        foreach (wp_remote_retrieve_headers($response) as $name => $value) {
            $responseHeaders[strtolower((string) $name)] = is_array($value) ? implode(', ', $value) : (string) $value;
        }

        return new HttpResponse((int) wp_remote_retrieve_response_code($response), $responseHeaders, (string) wp_remote_retrieve_body($response));
    }
}
