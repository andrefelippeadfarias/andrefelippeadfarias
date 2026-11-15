<?php

declare(strict_types=1);

namespace Ritmo\Tests\Unit\Connectors\Ai;

require_once dirname(__DIR__, 3) . '/fake-apis/AnthropicApi.php';

use Ritmo\Contracts\HttpClient;
use Ritmo\Contracts\HttpResponse;
use RitmoFakeApis\AnthropicApi;

/**
 * The fake Claude API (tests/fake-apis/AnthropicApi.php) answering in process, as an HttpClient:
 * the requests go through the simulator's own checks and log, without a socket. Requests to any
 * other host than the one it stands for fail the test.
 */
final class SimulatedAnthropic implements HttpClient
{
    public const BASE = 'https://api.anthropic.com';

    public readonly AnthropicApi $api;

    private readonly string $stateFile;

    /** @var list<array{method: string, url: string, headers: array<string, string>, timeout: int}> */
    private array $sent = [];

    public function __construct()
    {
        $this->stateFile = (string) tempnam(sys_get_temp_dir(), 'ritmo-anthropic');
        $this->api = new AnthropicApi($this->stateFile);
    }

    public function __destruct()
    {
        @unlink($this->stateFile);
    }

    public function request(string $method, string $url, array $headers = [], ?string $body = null, int $timeout = 30): HttpResponse
    {
        if (!str_starts_with($url, self::BASE . '/')) {
            throw new \LogicException('The simulator only stands for ' . self::BASE . '; asked for ' . $url);
        }
        $this->sent[] = ['method' => $method, 'url' => $url, 'headers' => $headers, 'timeout' => $timeout];
        $lower = [];
        foreach ($headers as $name => $value) {
            $lower[strtolower((string) $name)] = (string) $value;
        }
        $reply = $this->api->handle($method, (string) parse_url($url, PHP_URL_PATH), $lower, (string) $body);

        return new HttpResponse($reply->status, $reply->headers, $reply->body);
    }

    /**
     * @param list<array<string, mixed>> $replies
     */
    public function script(array ...$replies): self
    {
        $this->api->handle('POST', '/__anthropic/script', [], (string) json_encode(['replies' => $replies]));

        return $this;
    }

    /**
     * @return list<array{method: string, path: string, version: ?string, beta: ?string, has_key: bool, body: ?array<string, mixed>, status: int}>
     */
    public function log(): array
    {
        return json_decode($this->api->handle('GET', '/__anthropic/log', [], '')->body, true)['requests'];
    }

    /**
     * @return list<array{method: string, url: string, headers: array<string, string>, timeout: int}>
     */
    public function sent(): array
    {
        return $this->sent;
    }
}
