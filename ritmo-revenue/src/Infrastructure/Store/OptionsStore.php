<?php

declare(strict_types=1);

namespace Ritmo\Infrastructure\Store;

use InvalidArgumentException;
use JsonException;
use Ritmo\Contracts\KeyValueStore;
use stdClass;

/**
 * KeyValueStore over WordPress options: key "db_version" is the option "ritmo_db_version".
 *
 * Values are stored as JSON text, so what comes back has the type that went in (WordPress
 * alone would return numbers and booleans as strings) and nothing is ever unserialized into
 * an object. Options are not autoloaded.
 */
final class OptionsStore implements KeyValueStore
{
    private const KEY_PATTERN = '/^[A-Za-z0-9_.:-]{1,120}$/';

    public function __construct(private readonly string $prefix = 'ritmo_')
    {
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $missing = new stdClass();
        $stored = get_option($this->name($key), $missing);
        if (!is_string($stored)) {
            return $default;
        }

        try {
            return json_decode($stored, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return $default;
        }
    }

    public function has(string $key): bool
    {
        $missing = new stdClass();

        return get_option($this->name($key), $missing) !== $missing;
    }

    public function set(string $key, mixed $value): void
    {
        update_option($this->name($key), JsonValue::encode($key, $value), false);
    }

    public function delete(string $key): void
    {
        delete_option($this->name($key));
    }

    private function name(string $key): string
    {
        if (preg_match(self::KEY_PATTERN, $key) !== 1) {
            throw new InvalidArgumentException(sprintf('"%s" is not a valid key.', $key));
        }

        return $this->prefix . $key;
    }
}
