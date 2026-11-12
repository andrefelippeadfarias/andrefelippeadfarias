<?php

declare(strict_types=1);

namespace Ritmo\Tests\Unit\Infrastructure\Support;

use RuntimeException;

/**
 * PHP's built-in web server on a free port of 127.0.0.1, running one router script for the
 * life of the object. Nothing outside this machine is contacted.
 */
final class LocalServer
{
    /** @var resource */
    private $process;

    private function __construct($process, public readonly int $port)
    {
        $this->process = $process;
    }

    public static function start(string $router): self
    {
        $port = self::freePort();
        $process = proc_open(
            [PHP_BINARY, '-S', '127.0.0.1:' . $port, $router],
            [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
            $pipes
        );
        if (!is_resource($process)) {
            throw new RuntimeException('The local test server could not be started.');
        }

        $server = new self($process, $port);
        for ($attempt = 0; $attempt < 100; ++$attempt) {
            $socket = @fsockopen('127.0.0.1', $port, $code, $message, 0.1);
            if (is_resource($socket)) {
                fclose($socket);

                return $server;
            }
            usleep(50_000);
        }

        $server->stop();

        throw new RuntimeException('The local test server did not come up.');
    }

    /**
     * A port of 127.0.0.1 nothing listens on.
     */
    public static function freePort(): int
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        if ($socket === false) {
            throw new RuntimeException('No free port on 127.0.0.1.');
        }
        $port = (int) substr((string) strrchr((string) stream_socket_get_name($socket, false), ':'), 1);
        fclose($socket);

        return $port;
    }

    public function url(string $path = '/'): string
    {
        return 'http://127.0.0.1:' . $this->port . $path;
    }

    public function stop(): void
    {
        if (is_resource($this->process)) {
            proc_terminate($this->process);
            proc_close($this->process);
        }
    }

    public function __destruct()
    {
        $this->stop();
    }
}
