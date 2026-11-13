<?php

declare(strict_types=1);

namespace Ritmo\Admin\Editors;

use Ritmo\Domain\Config\FieldType;
use Ritmo\Domain\Config\Schema;

/**
 * A dotted path of the rules as a person reads it: "floors.owner.1.amount" is
 * "Floors › Owner floors › 2 › Amount" (a list item by its name, label or id when it has one,
 * else its position from 1). The screen never shows the dotted path itself (spec 05, 5.5.4).
 */
final class FieldPath
{
    public const SEPARATOR = ' › ';

    private function __construct()
    {
    }

    /**
     * @param array<string, mixed> $rules the rules the path points into (for item names)
     */
    public static function label(string $path, Schema $schema, array $rules): string
    {
        $parts = [];
        $key = '';
        $value = $rules;
        foreach (explode('.', $path) as $segment) {
            $parent = $key === '' ? null : ($schema->has($key) ? $schema->field($key) : null);
            $value = is_array($value) ? ($value[$segment] ?? null) : null;
            if ($parent !== null && in_array($parent->type, [FieldType::ListOf, FieldType::Map], true)) {
                $key .= '.' . Schema::WILDCARD;
                $parts[] = $parent->type === FieldType::ListOf ? self::itemName($value, (int) $segment) : $segment;
                continue;
            }
            $key = $key === '' ? $segment : $key . '.' . $segment;
            if (!$schema->has($key)) {
                $parts[] = $segment;
                continue;
            }
            $parts[] = $schema->field($key)->label;
        }

        return implode(self::SEPARATOR, $parts);
    }

    /**
     * The id of the form field a path is edited in ("ritmo-f-floors-owner-1-amount").
     */
    public static function anchor(string $path): string
    {
        return 'ritmo-f-' . preg_replace('/[^a-z0-9_-]+/', '-', strtolower($path));
    }

    private static function itemName(mixed $item, int $index): string
    {
        foreach (['name', 'label', 'id'] as $name) {
            if (is_array($item) && is_string($item[$name] ?? null) && $item[$name] !== '') {
                return $item[$name];
            }
        }

        return (string) ($index + 1);
    }
}
