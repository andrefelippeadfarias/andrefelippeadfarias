<?php

declare(strict_types=1);

namespace Ritmo\Infrastructure\Http;

use Ritmo\Contracts\Exception\ConnectorException;
use Ritmo\Contracts\HttpClient;
use Ritmo\Contracts\HttpResponse;

/**
 * HttpClient over cURL, for tests and command-line tools that run without WordPress.
 *
 * It sends exactly one request: redirects are not followed, so a request never changes host
 * or method on the way. It has no guard of its own; wrap it in GuardedHttpClient.
 */
final class CurlHttpClient implements HttpClient
{
    public function __construct(private readonly string $userAgent = 'RitmoRevenue')
    {
    }

    public function request(string $method, string $url, array $headers = [], ?string $body = null, int $timeout = 30): HttpResponse
    {
        $method = RequestLine::method($method);
        $target = RequestTarget::fromUrl($url);
        $timeout = max(1, $timeout);

        $responseHeaders = [];
        $handle = curl_init();
        curl_setopt_array($handle, [
            CURLOPT_URL => $url,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => RequestLine::headerLines($headers),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_CONNECTTIMEOUT => min(10, $timeout),
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_USERAGENT => $this->userAgent,
            CURLOPT_NOBODY => $method === 'HEAD',
            CURLOPT_HEADERFUNCTION => static function ($handle, string $line) use (&$responseHeaders): int {
                $separator = strpos($line, ':');
                if ($separator !== false) {
                    $name = strtolower(trim(substr($line, 0, $separator)));
                    $value = trim(substr($line, $separator + 1));
                    $responseHeaders[$name] = isset($responseHeaders[$name]) ? $responseHeaders[$name] . ', ' . $value : $value;
                }

                return strlen($line);
            },
        ]);
        if ($body !== null) {
            curl_setopt($handle, CURLOPT_POSTFIELDS, $body);
        }

        $responseBody = curl_exec($handle);
        $failure = curl_error($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);

        if (!is_string($responseBody)) {
            throw new ConnectorException(sprintf('No response from %s: %s.', $target->host, $failure !== '' ? $failure : 'unknown network error'), '', null, true);
        }

        return new HttpResponse($status, $responseHeaders, $responseBody);
    }
}
