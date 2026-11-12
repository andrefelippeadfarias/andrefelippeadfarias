<?php

declare(strict_types=1);

namespace Ritmo\Infrastructure\Http;

use InvalidArgumentException;

/**
 * Checks of the parts of a request that could be used to smuggle another one: the method
 * and the headers. Every HTTP client of the plugin runs them before sending.
 */
final class RequestLine
{
    /**
     * The method in capitals.
     *
     * @throws InvalidArgumentException when it is not a plain HTTP method name
     */
    public static function method(string $method): string
    {
        $method = strtoupper(trim($method));
        if (preg_match('/^[A-Z]{1,12}$/', $method) !== 1) {
            throw new InvalidArgumentException('The HTTP method is not valid.');
        }

        return $method;
    }

    /**
     * The headers as "Name: value" lines.
     *
     * @param array<array-key, mixed> $headers header name => value
     *
     * @return list<string>
     *
     * @throws InvalidArgumentException when a name is not a token or a value has a line break or control character
     */
    public static function headerLines(array $headers): array
    {
        $lines = [];
        foreach ($headers as $name => $value) {
            $name = (string) $name;
            if (!is_scalar($value) || preg_match('/^[A-Za-z0-9!#$%&\'*+.^_`|~-]+$/', $name) !== 1 || preg_match('/[\x00-\x08\x0A-\x1F\x7F]/', (string) $value) === 1) {
                throw new InvalidArgumentException('A request header is not valid.');
            }
            $lines[] = $name . ': ' . $value;
        }

        return $lines;
    }
}
