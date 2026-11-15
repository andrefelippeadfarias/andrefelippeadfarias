<?php

declare(strict_types=1);

namespace Ritmo\Tests\Integration\Update;

use RuntimeException;

/**
 * The fake licence and update server (tests/fake-apis/LicenceUpdateApi.php) on a free port of
 * 127.0.0.1, with a signing key pair generated for this run only (passed to the server through
 * its environment; never written to a file). The control plane is called with cURL directly.
 */
final class LicenceUpdateServer
{
    /** @var resource */
    private $process;

    /**
     * @param resource $process
     */
    private function __construct($process, public readonly int $port, private readonly string $stateFile, public readonly string $publicKey)
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
        $state = tempnam(sys_get_temp_dir(), 'ritmo-licence-sim');
        if ($state === false) {
            throw new RuntimeException('No temporary file for the simulator state.');
        }
        $pair = sodium_crypto_sign_keypair();
        $environment = getenv();
        $environment['RITMO_FAKE_LICENCE_STATE'] = $state;
        $environment['RITMO_FAKE_UPDATE_SECRET_KEY'] = base64_encode(sodium_crypto_sign_secretkey($pair));
        $process = proc_open(
            [PHP_BINARY, '-S', '127.0.0.1:' . $port, dirname(__DIR__, 2) . '/fake-apis/LicenceUpdateApi.php'],
            [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
            $pipes,
            null,
            $environment
        );
        if (!is_resource($process)) {
            throw new RuntimeException('The simulator could not be started.');
        }
        $server = new self($process, $port, $state, base64_encode(sodium_crypto_sign_publickey($pair)));
        for ($attempt = 0; $attempt < 100; ++$attempt) {
            $probe = @fsockopen('127.0.0.1', $port, $code, $message, 0.1);
            if (is_resource($probe)) {
                fclose($probe);

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

    public function licence(string $key, string $status = 'valid', ?string $expiresAt = null, int $maxSites = 1): void
    {
        $this->control('POST', '/__licence', ['key' => $key, 'status' => $status, 'expires_at' => $expiresAt, 'max_sites' => $maxSites]);
    }

    public function mode(string $mode): void
    {
        $this->control('POST', '/__mode', ['mode' => $mode]);
    }

    /**
     * @param list<string> $changelog
     */
    public function release(string $version, string $zipPath, string $tamper = 'none', array $changelog = ['A change.']): void
    {
        $this->control('POST', '/__release', ['version' => $version, 'zip_path' => $zipPath, 'tamper' => $tamper, 'changelog' => $changelog]);
    }

    /**
     * @return list<array{method: string, path: string, status: int, licence: string, site: string}>
     */
    public function requests(): array
    {
        return $this->control('GET', '/__log')['requests'];
    }

    /**
     * @return list<string>
     */
    public function lines(): array
    {
        return array_map(static fn (array $r): string => $r['method'] . ' ' . $r['path'] . ' ' . $r['status'], $this->requests());
    }

    public function reset(): void
    {
        $this->control('POST', '/__reset');
    }

    public function stateFile(): string
    {
        return $this->stateFile;
    }

    public function stop(): void
    {
        if (is_resource($this->process)) {
            proc_terminate($this->process);
            proc_close($this->process);
        }
        if (is_file($this->stateFile)) {
            unlink($this->stateFile);
        }
    }

    /**
     * @param array<string, mixed>|null $body
     *
     * @return array<string, mixed>
     */
    private function control(string $method, string $path, ?array $body = null): array
    {
        $handle = curl_init($this->url($path));
        curl_setopt_array($handle, [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10]);
        if ($body !== null) {
            curl_setopt($handle, CURLOPT_POSTFIELDS, (string) json_encode($body));
        }
        $answer = curl_exec($handle);
        if (!is_string($answer) || curl_getinfo($handle, CURLINFO_RESPONSE_CODE) !== 200) {
            throw new RuntimeException('The control plane of the simulator refused ' . $method . ' ' . $path . ': ' . (is_string($answer) ? $answer : curl_error($handle)));
        }

        return (array) json_decode($answer, true);
    }
}
