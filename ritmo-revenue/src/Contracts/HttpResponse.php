<?php

declare(strict_types=1);

namespace Ritmo\Contracts;

use Ritmo\Contracts\Exception\ConnectorException;

/**
 * An HTTP response as connectors see it.
 */
final class HttpResponse
{
    /** @var array<string, string> */
    private array $headers = [];

    /**
     * @param array<string, string> $headers header name => value; names are matched case-insensitively
     */
    public function __construct(
        private readonly int $status,
        array $headers = [],
        private readonly string $body = '',
    ) {
        foreach ($headers as $name => $value) {
            $this->headers[strtolower((string) $name)] = (string) $value;
        }
    }

    public function status(): int
    {
        return $this->status;
    }

    /**
     * True for a 2xx status.
     */
    public function isSuccess(): bool
    {
        return $this->status >= 200 && $this->status < 300;
    }

    /**
     * @return array<string, string> header names in lowercase
     */
    public function headers(): array
    {
        return $this->headers;
    }

    /**
     * The value of a header (case-insensitive), or null when absent.
     */
    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }

    public function body(): string
    {
        return $this->body;
    }

    /**
     * The body decoded as a JSON object or list.
     *
     * @return array<int|string, mixed>
     *
     * @throws ConnectorException when the body is not a JSON object or list
     */
    public function json(): array
    {
        try {
            $decoded = json_decode($this->body, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new ConnectorException('The response body is not valid JSON.', '', $this->status, false, $e);
        }
        if (!is_array($decoded)) {
            throw new ConnectorException('The response body is not a JSON object or list.', '', $this->status);
        }

        return $decoded;
    }
}
