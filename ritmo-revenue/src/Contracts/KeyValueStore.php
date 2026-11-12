<?php

declare(strict_types=1);

namespace Ritmo\Contracts;

/**
 * Small persistent state of the plugin (settings, cursors, last-known answers).
 *
 * Not for secrets: API keys live in the encrypted secrets vault. Values must be JSON-encodable
 * (scalars, null and arrays of those).
 */
interface KeyValueStore
{
    /**
     * The stored value, or $default when the key does not exist.
     */
    public function get(string $key, mixed $default = null): mixed;

    public function has(string $key): bool;

    public function set(string $key, mixed $value): void;

    public function delete(string $key): void;
}
