<?php

declare(strict_types=1);

namespace Ritmo\Tests\Unit\Infrastructure\Support;

/**
 * What the stand-ins for WordPress functions (declared next to the tests that use them, in
 * the namespace of the class under test) record and answer. PHP resolves an unqualified
 * function call in the caller's namespace first, so the code under test reaches the
 * stand-ins without WordPress being loaded and without touching the global namespace.
 */
final class WpFunctions
{
    /** @var array<string, mixed> stored options */
    public static array $options = [];

    /** @var list<array{0: string, 1: mixed, 2: mixed}> calls of update_option(): name, value, autoload */
    public static array $optionWrites = [];

    /** @var list<array{0: string, 1: array<string, mixed>}> calls of wp_remote_request(): url, arguments */
    public static array $requests = [];

    /** What wp_remote_request() answers: a response array or a WpErrorDouble. */
    public static mixed $response = null;

    public static function reset(): void
    {
        self::$options = [];
        self::$optionWrites = [];
        self::$requests = [];
        self::$response = null;
    }
}
