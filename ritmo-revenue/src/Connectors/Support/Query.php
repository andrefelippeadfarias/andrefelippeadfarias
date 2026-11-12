<?php

declare(strict_types=1);

namespace Ritmo\Connectors\Support;

/**
 * Builds a query string where a list becomes the same parameter repeated
 * (status=confirmed&status=new), the form the connected APIs expect.
 */
final class Query
{
    /**
     * @param array<string, scalar|list<scalar>> $parameters
     */
    public static function build(array $parameters): string
    {
        $pairs = [];
        foreach ($parameters as $name => $value) {
            foreach (is_array($value) ? $value : [$value] as $item) {
                $pairs[] = rawurlencode((string) $name) . '=' . rawurlencode(self::text($item));
            }
        }

        return implode('&', $pairs);
    }

    /**
     * The URL with the parameters appended, when there are any.
     *
     * @param array<string, scalar|list<scalar>> $parameters
     */
    public static function append(string $url, array $parameters): string
    {
        $query = self::build($parameters);

        return $query === '' ? $url : $url . '?' . $query;
    }

    private static function text(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        return (string) $value;
    }
}
