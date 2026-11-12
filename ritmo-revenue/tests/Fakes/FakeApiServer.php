<?php

declare(strict_types=1);

namespace Ritmo\Tests\Fakes;

use RuntimeException;

/**
 * The fake Beds24 and PriceLabs APIs (tests/fake-apis/router.php) served by PHP's built-in
 * server on a free port of 127.0.0.1, with a request log of their own.
 *
 * The control plane (/__log, /__reset, /__fault, /__revoke) is called with cURL directly, so a
 * test reads the log without going through the client under test.
 */
final class FakeApiServer
{
    /** @var resource */
    private $process;

    private function __construct($process, public readonly int $port, private readonly string $logFile)
    {
        $this->process = $process;
    }

    /**
     * @param string|null $start YYYY-MM-DD the scenario's first night is moved to; null keeps the fixture's dates
     */
    public static function start(?string $start = null): self
    {
        $port = self::freePort();
        $logFile = tempnam(sys_get_temp_dir(), 'ritmo-fake-api');
        if ($logFile === false) {
            throw new RuntimeException('No temporary file for the simulator log.');
        }

        $environment = getenv();
        $environment['RITMO_FAKE_API_LOG'] = $logFile;
        if ($start !== null) {
            $environment['RITMO_FAKE_API_START'] = $start;
        }

        $process = proc_open(
            [PHP_BINARY, '-S', '127.0.0.1:' . $port, dirname(__DIR__) . '/fake-apis/router.php'],
            [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
            $pipes,
            null,
            $environment
        );
        if (!is_resource($process)) {
            throw new RuntimeException('The simulator could not be started.');
        }

        $server = new self($process, $port, $logFile);
        for ($attempt = 0; $attempt < 100; ++$attempt) {
            $socket = @fsockopen('127.0.0.1', $port, $code, $message, 0.1);
            if (is_resource($socket)) {
                fclose($socket);

                return $server;
            }
            usleep(50_000);
        }

        $server->stop();

        throw new RuntimeException('The simulator did not come up.');
    }

    public function url(string $path = ''): string
    {
        return 'http://127.0.0.1:' . $this->port . $path;
    }

    public function beds24BaseUrl(): string
    {
        return $this->url('/beds24/v2');
    }

    public function priceLabsBaseUrl(): string
    {
        return $this->url('/pricelabs/v1');
    }

    /**
     * Requests the APIs received since the last reset, control plane left out.
     *
     * @return list<array{method: string, path: string, query: string, status: int}>
     */
    public function requests(): array
    {
        $log = $this->control('GET', '/__log');

        return array_values(array_filter($log['requests'], static fn (array $request): bool => !str_starts_with($request['path'], '/__')));
    }

    /**
     * "METHOD /path" of every request the APIs received, in order.
     *
     * @return list<string>
     */
    public function lines(): array
    {
        return array_map(static fn (array $request): string => $request['method'] . ' ' . $request['path'], $this->requests());
    }

    public function reset(): void
    {
        $this->control('POST', '/__reset');
    }

    /**
     * Every Beds24 access token issued so far stops working.
     */
    public function revokeAccessTokens(): void
    {
        $this->control('POST', '/__revoke');
    }

    /**
     * The next $times requests to the method and full path answer with the status and headers
     * (status 0: they answer normally with the headers added).
     *
     * @param array<string, string> $headers
     */
    public function fault(string $method, string $path, int $status, array $headers = [], int $times = 1): void
    {
        $this->control('POST', '/__fault', ['method' => $method, 'path' => $path, 'status' => $status, 'headers' => $headers, 'times' => $times]);
    }

    public function stop(): void
    {
        if (is_resource($this->process)) {
            proc_terminate($this->process);
            proc_close($this->process);
        }
        foreach ([$this->logFile, $this->logFile . '.state'] as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
    }

    public function __destruct()
    {
        $this->stop();
    }

    /**
     * @param array<string, mixed>|null $body
     *
     * @return array<string, mixed>
     */
    private function control(string $method, string $path, ?array $body = null): array
    {
        $curl = curl_init($this->url($path));
        curl_setopt_array($curl, [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10]);
        if ($method !== 'GET') {
            curl_setopt_array($curl, [
                CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
                CURLOPT_POSTFIELDS => $body === null ? '{}' : json_encode($body, JSON_THROW_ON_ERROR),
            ]);
        }
        $answer = curl_exec($curl);
        $status = curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        curl_close($curl);
        if (!is_string($answer) || $status !== 200) {
            throw new RuntimeException(sprintf('The simulator control request %s %s failed (HTTP %d).', $method, $path, $status));
        }

        $decoded = json_decode($answer, true, 16, JSON_THROW_ON_ERROR);

        return is_array($decoded) ? $decoded : [];
    }

    private static function freePort(): int
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        if ($socket === false) {
            throw new RuntimeException('No free port on 127.0.0.1.');
        }
        $port = (int) substr((string) strrchr((string) stream_socket_get_name($socket, false), ':'), 1);
        fclose($socket);

        return $port;
    }
}
