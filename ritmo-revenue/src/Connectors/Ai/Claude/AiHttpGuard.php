<?php

declare(strict_types=1);

namespace Ritmo\Connectors\Ai\Claude;

use Ritmo\Contracts\Exception\WriteBlocked;
use Ritmo\Contracts\HttpClient;
use Ritmo\Contracts\HttpResponse;

/**
 * The allow-list of the assistant, in front of the HTTP client it is given: exactly two requests
 * may leave the site, POST {base}/v1/messages and GET {base}/v1/models, to the one host of the
 * configured base URL. Anything else (another host, path or method, a method-override header) is
 * refused before the wrapped client is called. The pricing and PMS connectors never get this
 * client, and the AI host is never on their lists.
 */
final class AiHttpGuard implements HttpClient
{
    private const OVERRIDE_HEADERS = ['x-http-method-override', 'x-http-method', 'x-method-override'];

    public function __construct(private readonly HttpClient $inner, private readonly string $baseUrl = ClaudeConfig::BASE_URL)
    {
    }

    public function request(string $method, string $url, array $headers = [], ?string $body = null, int $timeout = 30): HttpResponse
    {
        $method = strtoupper($method);
        $reason = $this->refusal($method, $url, $headers);
        if ($reason !== null) {
            throw WriteBlocked::forRequest($method, (string) preg_replace('/[?#].*$/s', '', $url), $reason);
        }

        return $this->inner->request($method, $url, $headers, $body, $timeout);
    }

    /**
     * @param array<array-key, mixed> $headers
     */
    private function refusal(string $method, string $url, array $headers): ?string
    {
        foreach (array_keys($headers) as $name) {
            if (in_array(strtolower((string) $name), self::OVERRIDE_HEADERS, true)) {
                return 'the request carries a method-override header';
            }
        }
        $base = rtrim($this->baseUrl, '/');
        if ($method === 'POST' && $url === $base . ClaudeConfig::MESSAGES_PATH) {
            return null;
        }
        if ($method === 'GET' && preg_match('#^' . preg_quote($base . ClaudeConfig::MODELS_PATH, '#') . '(\?limit=\d{1,4})?$#', $url) === 1) {
            return null;
        }

        return 'only the messages and models routes of the assistant may leave the site';
    }
}
