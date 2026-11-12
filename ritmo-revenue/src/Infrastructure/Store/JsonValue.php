<?php

declare(strict_types=1);

namespace Ritmo\Infrastructure\Store;

use InvalidArgumentException;
use JsonException;

/**
 * The check every KeyValueStore applies to what it is asked to keep: the value must be JSON
 * text that reads back as exactly the same value (scalars, null and arrays of those).
 */
final class JsonValue
{
    /**
     * The value as JSON text.
     *
     * @throws InvalidArgumentException when the value cannot be encoded or would come back different (an object, NaN, invalid text)
     */
    public static function encode(string $key, mixed $value): string
    {
        try {
            $encoded = json_encode($value, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $restored = json_decode($encoded, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $failure) {
            throw new InvalidArgumentException(sprintf('The value of "%s" is not JSON-encodable.', $key), 0, $failure);
        }
        if ($restored !== $value) {
            throw new InvalidArgumentException(sprintf('The value of "%s" would not survive storage (objects are not allowed).', $key));
        }

        return $encoded;
    }
}
