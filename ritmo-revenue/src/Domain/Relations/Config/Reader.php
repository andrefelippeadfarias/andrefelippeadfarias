<?php

declare(strict_types=1);

namespace Ritmo\Domain\Relations\Config;

use BackedEnum;
use DateTimeImmutable;
use Ritmo\Domain\Config\ValidationError;
use Ritmo\Domain\Support\Days;

/**
 * Reads typed values out of one level of a configuration array and records a ValidationError,
 * with its dotted path, for every missing, mistyped or out-of-range value.
 *
 * A failed read returns the default, so that parsing can go on and report every error at once.
 * Types are not coerced, except that a whole number is accepted where a number is expected.
 */
final class Reader
{
    /**
     * @param array<int|string, mixed> $data
     */
    public function __construct(
        private readonly array $data,
        private readonly string $path,
        private readonly ErrorList $errors,
    ) {
    }

    public function errors(): ErrorList
    {
        return $this->errors;
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->data);
    }

    public function pathOf(string $key): string
    {
        return $this->path === '' ? $key : $this->path . '.' . $key;
    }

    /**
     * @param list<string> $known
     */
    public function rejectUnknown(array $known): void
    {
        foreach (array_keys($this->data) as $key) {
            if (!in_array((string) $key, $known, true)) {
                $this->errors->add($this->pathOf((string) $key), ValidationError::UNKNOWN, 'is not a known field');
            }
        }
    }

    public function text(string $key, ?string $default = null, ?string $pattern = null, int $maxLength = 200): string
    {
        $value = $this->value($key, $default);
        if (!is_string($value)) {
            return $this->fail($key, ValidationError::TYPE, 'must be text', $default ?? '');
        }
        if ((int) preg_match_all('/./su', $value) > $maxLength) {
            return $this->fail($key, ValidationError::RANGE, sprintf('must have at most %d characters', $maxLength), $default ?? '');
        }
        if ($pattern !== null && preg_match($pattern, $value) !== 1) {
            return $this->fail($key, ValidationError::FORMAT, 'has an invalid format', $default ?? '');
        }

        return $value;
    }

    public function optionalText(string $key, ?string $pattern = null): ?string
    {
        if (($this->data[$key] ?? null) === null) {
            return null;
        }

        return $this->text($key, null, $pattern);
    }

    public function number(string $key, ?float $default, float $min, float $max): float
    {
        $value = $this->value($key, $default);
        if ((!is_int($value) && !is_float($value)) || !is_finite((float) $value)) {
            return $this->fail($key, ValidationError::TYPE, 'must be a number', $default ?? $min);
        }
        if ($value < $min || $value > $max) {
            return $this->fail($key, ValidationError::RANGE, sprintf('must be between %s and %s', $min, $max), $default ?? $min);
        }

        return (float) $value;
    }

    public function integer(string $key, ?int $default, int $min, int $max): int
    {
        $value = $this->value($key, $default);
        if (!is_int($value)) {
            return $this->fail($key, ValidationError::TYPE, 'must be a whole number', $default ?? $min);
        }
        if ($value < $min || $value > $max) {
            return $this->fail($key, ValidationError::RANGE, sprintf('must be between %d and %d', $min, $max), $default ?? $min);
        }

        return $value;
    }

    public function optionalInteger(string $key, int $min, int $max): ?int
    {
        if (($this->data[$key] ?? null) === null) {
            return null;
        }

        return $this->integer($key, null, $min, $max);
    }

    public function flag(string $key, bool $default): bool
    {
        $value = $this->value($key, $default);
        if (!is_bool($value)) {
            return $this->fail($key, ValidationError::TYPE, 'must be true or false', $default);
        }

        return $value;
    }

    /**
     * @template T of BackedEnum
     *
     * @param class-string<T> $enum
     * @param T|null          $default
     *
     * @return T
     */
    public function choice(string $key, string $enum, ?BackedEnum $default): BackedEnum
    {
        $value = $this->value($key, $default?->value);
        $case = is_string($value) ? $enum::tryFrom($value) : null;
        if ($case === null) {
            $accepted = implode(', ', array_map(static fn (BackedEnum $c): string => (string) $c->value, $enum::cases()));

            return $this->fail($key, ValidationError::CHOICE, 'must be one of: ' . $accepted, $default ?? $enum::cases()[0]);
        }

        return $case;
    }

    /**
     * A list of values of an enum, without repeats.
     *
     * @template T of BackedEnum
     *
     * @param class-string<T> $enum
     *
     * @return list<T>
     */
    public function choices(string $key, string $enum): array
    {
        $cases = [];
        foreach ($this->list($key) as $index => $value) {
            $case = is_string($value) ? $enum::tryFrom($value) : null;
            if ($case === null) {
                $this->errors->add($this->pathOf($key) . '.' . $index, ValidationError::CHOICE, 'is not an accepted value');
                continue;
            }
            $cases[] = $case;
        }
        if (count($cases) !== count(array_unique(array_map(static fn (BackedEnum $c): string => (string) $c->value, $cases)))) {
            $this->errors->add($this->pathOf($key), ValidationError::UNIQUE, 'must not repeat items');
        }

        return $cases;
    }

    /**
     * A list of texts, each matching the pattern, without repeats.
     *
     * @return list<string>
     */
    public function texts(string $key, string $pattern, int $maxItems): array
    {
        $texts = [];
        $items = $this->list($key);
        if (count($items) > $maxItems) {
            $this->errors->add($this->pathOf($key), ValidationError::RANGE, sprintf('must have at most %d items', $maxItems));
        }
        foreach ($items as $index => $value) {
            if (!is_string($value) || preg_match($pattern, $value) !== 1) {
                $this->errors->add($this->pathOf($key) . '.' . $index, ValidationError::FORMAT, 'has an invalid format');
                continue;
            }
            $texts[] = $value;
        }
        if (count($texts) !== count(array_unique($texts))) {
            $this->errors->add($this->pathOf($key), ValidationError::UNIQUE, 'must not repeat items');
        }

        return $texts;
    }

    /**
     * A date as YYYY-MM-DD.
     */
    public function date(string $key): ?DateTimeImmutable
    {
        $value = $this->value($key, null);
        if (!is_string($value) || !Days::isValid($value)) {
            $this->errors->add($this->pathOf($key), ValidationError::FORMAT, 'must be a date as YYYY-MM-DD');

            return null;
        }

        return Days::date($value);
    }

    /**
     * A list (empty when missing).
     *
     * @return list<mixed>
     */
    public function list(string $key): array
    {
        $value = $this->data[$key] ?? [];
        if (!is_array($value) || !array_is_list($value)) {
            $this->errors->add($this->pathOf($key), ValidationError::TYPE, 'must be a list');

            return [];
        }

        return $value;
    }

    /**
     * The keys of a nested map (empty when missing or not a map).
     *
     * @return list<int|string>
     */
    public function keysOf(string $key): array
    {
        $value = $this->data[$key] ?? [];

        return is_array($value) ? array_keys($value) : [];
    }

    /**
     * A reader of a nested group (empty when missing).
     */
    public function group(string $key): self
    {
        $value = $this->data[$key] ?? [];
        if (!is_array($value) || ($value !== [] && array_is_list($value))) {
            $this->errors->add($this->pathOf($key), ValidationError::TYPE, 'must be a group of fields');
            $value = [];
        }

        return new self($value, $this->pathOf($key), $this->errors);
    }

    /**
     * A reader of one item of a list.
     */
    public function item(string $key, int $index, mixed $value): self
    {
        if (!is_array($value) || ($value !== [] && array_is_list($value))) {
            $this->errors->add($this->pathOf($key) . '.' . $index, ValidationError::TYPE, 'must be a group of fields');
            $value = [];
        }

        return new self($value, $this->pathOf($key) . '.' . $index, $this->errors);
    }

    public function error(string $key, string $code, string $message): void
    {
        $this->errors->add($this->pathOf($key), $code, $message);
    }

    private function value(string $key, mixed $default): mixed
    {
        if (!array_key_exists($key, $this->data) || $this->data[$key] === null) {
            if ($default === null) {
                $this->errors->add($this->pathOf($key), ValidationError::REQUIRED, 'is required');
            }

            return $default;
        }

        return $this->data[$key];
    }

    /**
     * @template T
     *
     * @param T $fallback
     *
     * @return T
     */
    private function fail(string $key, string $code, string $message, mixed $fallback): mixed
    {
        if (array_key_exists($key, $this->data) && $this->data[$key] !== null) {
            $this->errors->add($this->pathOf($key), $code, $message);
        }

        return $fallback;
    }
}
