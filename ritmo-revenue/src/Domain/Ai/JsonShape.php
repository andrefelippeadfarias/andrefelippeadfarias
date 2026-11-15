<?php

declare(strict_types=1);

namespace Ritmo\Domain\Ai;

/**
 * Checks decoded JSON against the small subset of JSON schema the plugin's output schemas use:
 * type (object, array, string, number, integer, boolean), enum, properties, required,
 * additionalProperties false and items. The API already constrains the answer; this is the
 * plugin's own second check, so a malformed or tampered answer never reaches a draft. Texts are
 * bounded too (the schema itself cannot say so).
 */
final class JsonShape
{
    public const MAX_TEXT = 2000;

    public const MAX_ITEMS = 400;

    private function __construct()
    {
    }

    /**
     * The problems of the value, each as "path: what is wrong"; empty when it fits.
     *
     * @param array<string, mixed> $schema
     *
     * @return list<string>
     */
    public static function problems(mixed $value, array $schema, string $path = '$'): array
    {
        $type = $schema['type'] ?? null;
        $ok = match ($type) {
            'object' => is_array($value) && ($value === [] || !array_is_list($value)),
            'array' => is_array($value) && array_is_list($value),
            'string' => is_string($value),
            'number' => is_int($value) || is_float($value),
            'integer' => is_int($value),
            'boolean' => is_bool($value),
            default => false,
        };
        if (!$ok) {
            return [$path . ': expected ' . (is_string($type) ? $type : 'a known type')];
        }
        if (isset($schema['enum']) && !in_array($value, $schema['enum'], true)) {
            return [$path . ': not one of the allowed values'];
        }
        if (is_string($value) && mb_strlen($value) > self::MAX_TEXT) {
            return [$path . ': text too long'];
        }

        return match ($type) {
            'object' => self::objectProblems($value, $schema, $path),
            'array' => self::listProblems($value, $schema, $path),
            default => [],
        };
    }

    /**
     * @param array<string, mixed> $value
     * @param array<string, mixed> $schema
     *
     * @return list<string>
     */
    private static function objectProblems(array $value, array $schema, string $path): array
    {
        $properties = is_array($schema['properties'] ?? null) ? $schema['properties'] : [];
        $problems = [];
        foreach ((array) ($schema['required'] ?? []) as $required) {
            if (!array_key_exists($required, $value)) {
                $problems[] = $path . '.' . $required . ': missing';
            }
        }
        foreach ($value as $key => $item) {
            if (!isset($properties[$key])) {
                $problems[] = $path . '.' . $key . ': not allowed';
                continue;
            }
            $problems = [...$problems, ...self::problems($item, $properties[$key], $path . '.' . $key)];
        }

        return $problems;
    }

    /**
     * @param list<mixed>          $value
     * @param array<string, mixed> $schema
     *
     * @return list<string>
     */
    private static function listProblems(array $value, array $schema, string $path): array
    {
        if (count($value) > self::MAX_ITEMS) {
            return [$path . ': too many items'];
        }
        $problems = [];
        foreach ($value as $index => $item) {
            $problems = [...$problems, ...self::problems($item, is_array($schema['items'] ?? null) ? $schema['items'] : [], $path . '[' . $index . ']')];
        }

        return $problems;
    }
}
