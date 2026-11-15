<?php

declare(strict_types=1);

namespace Ritmo\Connectors\Licence;

use InvalidArgumentException;

/**
 * The address of the licence or update server, from a constant of wp-config.php (never from a
 * stored setting, so nothing saved in the database can send the licence key elsewhere).
 *
 * It is an https URL without credentials, query or fragment. Plain http is accepted only for a
 * loopback host (the local simulator) and only in development mode.
 */
final class ServerUrl
{
    private const LOOPBACK = ['127.0.0.1', 'localhost', '[::1]', '::1'];

    private function __construct(
        public readonly string $url,
        public readonly string $scheme,
        public readonly string $host,
        public readonly ?int $port,
    ) {
    }

    /**
     * @throws InvalidArgumentException when the text is not such a URL
     */
    public static function parse(string $text, bool $devMode): self
    {
        $text = trim($text);
        if ($text === '' || strlen($text) > 500 || preg_match('/[\x00-\x20\x7f\\\\]/', $text) === 1) {
            throw new InvalidArgumentException('The server address is an absolute https URL.');
        }
        $parts = parse_url($text);
        if (!is_array($parts) || !isset($parts['scheme'], $parts['host']) || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) {
            throw new InvalidArgumentException('The server address is an absolute https URL without credentials, query or fragment.');
        }
        $scheme = strtolower($parts['scheme']);
        $host = strtolower($parts['host']);
        if ($scheme !== 'https' && !($scheme === 'http' && $devMode && in_array($host, self::LOOPBACK, true))) {
            throw new InvalidArgumentException('The server address must use https (plain http only for the local simulator in development mode).');
        }
        $port = isset($parts['port']) ? (int) $parts['port'] : null;
        $path = isset($parts['path']) ? rtrim($parts['path'], '/') : '';
        if ($path !== '' && preg_match('#^(/[A-Za-z0-9._~-]+)+$#', $path) !== 1) {
            throw new InvalidArgumentException('The path of the server address has letters, digits, dots, hyphens and slashes only.');
        }

        return new self($scheme . '://' . $host . ($port !== null ? ':' . $port : '') . $path, $scheme, $host, $port);
    }

    /**
     * The URL of a constant, or null when the constant is missing, empty or not a valid address.
     */
    public static function tryParse(mixed $text, bool $devMode): ?self
    {
        if (!is_string($text) || trim($text) === '') {
            return null;
        }
        try {
            return self::parse($text, $devMode);
        } catch (InvalidArgumentException) {
            return null;
        }
    }

    /**
     * scheme://host[:port], the part two URLs must share to come from the same server.
     */
    public function origin(): string
    {
        return $this->scheme . '://' . $this->host . ($this->port !== null ? ':' . $this->port : '');
    }

    /**
     * True when $url is on the same scheme, host and port as this address.
     */
    public function sameOrigin(string $url, bool $devMode): bool
    {
        $other = self::tryParse($url, $devMode);

        return $other !== null && $other->origin() === $this->origin();
    }

    /**
     * An endpoint under this address ("activate" gives <url>/activate).
     */
    public function endpoint(string $name): string
    {
        if (preg_match('/^[a-z][a-z0-9_-]*$/', $name) !== 1) {
            throw new InvalidArgumentException('An endpoint is a plain lowercase name.');
        }

        return $this->url . '/' . $name;
    }
}
