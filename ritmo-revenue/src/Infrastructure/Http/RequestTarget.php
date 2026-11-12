<?php

declare(strict_types=1);

namespace Ritmo\Infrastructure\Http;

use InvalidArgumentException;

/**
 * Where a request goes, reduced to what the guards compare and the log may show: the host
 * (with the port when it is not the scheme's default) and the path. The query string, which
 * may carry a credential, is deliberately not kept.
 */
final class RequestTarget
{
    private function __construct(
        public readonly string $scheme,
        public readonly string $host,
        public readonly string $path,
    ) {
    }

    /**
     * @throws InvalidArgumentException when the URL is not an absolute http or https address
     */
    public static function fromUrl(string $url): self
    {
        $parts = preg_match('/[\x00-\x20\x7F]/', $url) === 1 ? false : parse_url($url);
        $scheme = is_array($parts) ? strtolower((string) ($parts['scheme'] ?? '')) : '';
        $host = is_array($parts) ? strtolower((string) ($parts['host'] ?? '')) : '';
        if (!in_array($scheme, ['http', 'https'], true) || $host === '') {
            throw new InvalidArgumentException('A request needs an absolute http or https URL.');
        }

        $port = $parts['port'] ?? null;
        if ($port !== null && $port !== ($scheme === 'https' ? 443 : 80)) {
            $host .= ':' . $port;
        }
        $path = (string) ($parts['path'] ?? '');

        return new self($scheme, $host, $path === '' ? '/' : $path);
    }

    /**
     * Host and path, for messages and the log.
     */
    public function __toString(): string
    {
        return $this->host . $this->path;
    }
}
