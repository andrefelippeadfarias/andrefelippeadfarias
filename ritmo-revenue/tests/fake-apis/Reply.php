<?php

declare(strict_types=1);

namespace RitmoFakeApis;

/**
 * What the simulator answers to one request.
 */
final class Reply
{
    /**
     * @param array<string, string> $headers
     */
    public function __construct(
        public readonly int $status,
        public readonly string $body = '',
        public readonly array $headers = [],
    ) {
    }

    /**
     * @param array<int|string, mixed> $data
     * @param array<string, string>    $headers
     */
    public static function json(int $status, array $data, array $headers = []): self
    {
        return new self(
            $status,
            json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            ['Content-Type' => 'application/json'] + $headers
        );
    }

    /**
     * The same reply with more headers; a header given here replaces one of the same name.
     *
     * @param array<string, string> $headers
     */
    public function withHeaders(array $headers): self
    {
        $merged = $this->headers;
        foreach ($headers as $name => $value) {
            foreach (array_keys($merged) as $existing) {
                if (strcasecmp($existing, $name) === 0) {
                    unset($merged[$existing]);
                }
            }
            $merged[$name] = $value;
        }

        return new self($this->status, $this->body, $merged);
    }

    public function emit(): void
    {
        http_response_code($this->status);
        foreach ($this->headers as $name => $value) {
            header($name . ': ' . $value);
        }
        echo $this->body;
    }
}
