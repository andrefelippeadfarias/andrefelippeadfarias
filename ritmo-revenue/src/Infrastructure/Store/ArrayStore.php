<?php

declare(strict_types=1);

namespace Ritmo\Infrastructure\Store;

use InvalidArgumentException;
use Ritmo\Contracts\KeyValueStore;

/**
 * KeyValueStore kept in memory, for tests and command-line tools. It accepts only what a
 * persistent store can keep (JSON-encodable values), so a test fails on the same values the
 * real store would mangle.
 */
final class ArrayStore implements KeyValueStore
{
    /** @var array<string, mixed> */
    private array $values = [];

    /**
     * @param array<string, mixed> $values initial content
     */
    public function __construct(array $values = [])
    {
        foreach ($values as $key => $value) {
            $this->set((string) $key, $value);
        }
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return array_key_exists($key, $this->values) ? $this->values[$key] : $default;
    }

    /**
     * The values live in this object only: there is no other copy to be stale against.
     */
    public function getFresh(string $key, mixed $default = null): mixed
    {
        return $this->get($key, $default);
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->values);
    }

    public function set(string $key, mixed $value): void
    {
        if ($key === '') {
            throw new InvalidArgumentException('A key cannot be empty.');
        }
        JsonValue::encode($key, $value);

        $this->values[$key] = $value;
    }

    public function delete(string $key): void
    {
        unset($this->values[$key]);
    }

    /**
     * Every key and value, for assertions.
     *
     * @return array<string, mixed>
     */
    public function all(): array
    {
        return $this->values;
    }
}
