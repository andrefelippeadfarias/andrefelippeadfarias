<?php

declare(strict_types=1);

namespace Ritmo\Tests\Integration\Ai;

use RuntimeException;

/**
 * The fake Claude API (tests/fake-apis/AnthropicApi.php) served by PHP's built-in server on a
 * free port of 127.0.0.1. Its control plane is called with cURL directly, so a test reads the log
 * without going through the client under test.
 */
final class AnthropicServer
{
    /** @var resource */
    private $process;

    private function __construct($process, public readonly int $port, private readonly string $stateFile)
    {
        $this->process = $process;
    }

    public static function start(): self
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        if ($socket === false) {
            throw new RuntimeException('No free port.');
        }
        $port = (int) substr((string) stream_socket_get_name($socket, false), strrpos((string) stream_socket_get_name($socket, false), ':') + 1);
        fclose($socket);
        $state = (string) tempnam(sys_get_temp_dir(), 'ritmo-anthropic');
        $environment = getenv();
        $environment['RITMO_FAKE_ANTHROPIC_STATE'] = $state;
        $process = proc_open(
            [PHP_BINARY, '-S', '127.0.0.1:' . $port, dirname(__DIR__, 2) . '/fake-apis/AnthropicApi.php'],
            [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
            $pipes,
            null,
            $environment
        );
        if (!is_resource($process)) {
            throw new RuntimeException('The simulator could not be started.');
        }
        $server = new self($process, $port, $state);
        for ($attempt = 0; $attempt < 100; ++$attempt) {
            $probe = @fsockopen('127.0.0.1', $port, $code, $message, 0.1);
            if (is_resource($probe)) {
                fclose($probe);

                return $server;
            }
            usleep(50000);
        }
        $server->stop();

        throw new RuntimeException('The simulator did not come up.');
    }

    public function baseUrl(): string
    {
        return 'http://127.0.0.1:' . $this->port . '/anthropic';
    }

    public function stop(): void
    {
        if (is_resource($this->process)) {
            proc_terminate($this->process);
            proc_close($this->process);
        }
        @unlink($this->stateFile);
    }

    /**
     * @param array<string, mixed> ...$replies
     */
    public function script(array ...$replies): void
    {
        $this->control('POST', '/__anthropic/script', (string) json_encode(['replies' => $replies]));
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function log(): array
    {
        return json_decode($this->control('GET', '/__anthropic/log', null), true)['requests'] ?? [];
    }

    private function control(string $method, string $path, ?string $body): string
    {
        $curl = curl_init('http://127.0.0.1:' . $this->port . $path);
        curl_setopt_array($curl, [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10]);
        if ($body !== null) {
            curl_setopt($curl, CURLOPT_POSTFIELDS, $body);
        }
        $answer = curl_exec($curl);
        curl_close($curl);

        return is_string($answer) ? $answer : '';
    }
}
