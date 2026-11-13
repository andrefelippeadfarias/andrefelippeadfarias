<?php

declare(strict_types=1);

namespace Ritmo\Tests\Support;

use OutOfBoundsException;

/**
 * The names of the reference operation (rooms, properties, relation ids, golden files) under
 * neutral keys, read from tests/fixtures/parity-names.json: the tests outside the fixtures never
 * spell them, and the vocabulary test scans them too.
 */
final class ParityNames
{
    /** @var array<string, array<string, string>>|null */
    private static ?array $names = null;

    private function __construct()
    {
    }

    public static function room(string $key): string
    {
        return self::name('rooms', $key);
    }

    public static function property(string $key): string
    {
        return self::name('properties', $key);
    }

    public static function relation(string $key): string
    {
        return self::name('relations', $key);
    }

    /**
     * The name of a golden file of the relations, without ".json".
     */
    public static function golden(string $key): string
    {
        return self::name('golden', $key);
    }

    private static function name(string $group, string $key): string
    {
        self::$names ??= Fixtures::json('parity-names.json');

        return self::$names[$group][$key] ?? throw new OutOfBoundsException(sprintf('No parity name "%s.%s".', $group, $key));
    }
}
