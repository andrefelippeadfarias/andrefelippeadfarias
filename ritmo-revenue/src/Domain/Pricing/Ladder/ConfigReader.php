<?php

declare(strict_types=1);

namespace Ritmo\Domain\Pricing\Ladder;

use DateTimeImmutable;
use LogicException;
use Ritmo\Domain\Config\Field;
use Ritmo\Domain\Config\FieldType;
use Ritmo\Domain\Config\InvalidConfig;
use Ritmo\Domain\Config\Schema;
use Ritmo\Domain\Config\ValidationError;
use Ritmo\Domain\Support\Days;

/**
 * Reads one configuration section against its own field definitions.
 *
 * The configuration objects of the ladder, the sold-out protection, the cadence and the owner
 * orders describe their fields with {@see Field} (type, range, neutral default, label, help), the
 * same definitions the integration adds to the rule set schema. This reader fills the defaults
 * from those definitions, rejects unknown keys and checks every value it hands out against its
 * field, collecting every problem; {@see finish()} throws them all at once.
 */
final class ConfigReader
{
    private readonly Schema $schema;

    /** @var list<ValidationError> */
    private array $errors = [];

    /**
     * @param list<Field> $fields parents before their children
     */
    public function __construct(array $fields)
    {
        $this->schema = new Schema($fields);
    }

    /**
     * The section under $root with every missing default filled in. Unknown keys are reported.
     *
     * @param array<int|string, mixed> $data the section itself (what sits under $root)
     *
     * @return array<int|string, mixed>
     */
    public function open(string $root, array $data): array
    {
        $filled = $this->schema->withDefaults([$root => $data])[$root];
        $this->checkKeys($root, $filled);

        return $filled;
    }

    public function int(mixed $value, string $path): int
    {
        return $this->scalar($value, $path, FieldType::Integer) ?? 0;
    }

    public function nullableInt(mixed $value, string $path): ?int
    {
        return $this->scalar($value, $path, FieldType::Integer);
    }

    public function float(mixed $value, string $path): float
    {
        return $this->scalar($value, $path, FieldType::Number) ?? 0.0;
    }

    public function nullableFloat(mixed $value, string $path): ?float
    {
        return $this->scalar($value, $path, FieldType::Number);
    }

    public function bool(mixed $value, string $path): bool
    {
        return $this->scalar($value, $path, FieldType::Boolean) ?? false;
    }

    public function string(mixed $value, string $path): string
    {
        return $this->scalar($value, $path, FieldType::Text) ?? '';
    }

    public function nullableString(mixed $value, string $path): ?string
    {
        return $this->scalar($value, $path, FieldType::Text);
    }

    public function choice(mixed $value, string $path): string|int
    {
        return $this->scalar($value, $path, FieldType::Choice) ?? $this->field($path)->default;
    }

    public function date(mixed $value, string $path): ?DateTimeImmutable
    {
        $text = $this->scalar($value, $path, FieldType::Date);

        return $text === null ? null : Days::date($text);
    }

    /**
     * A list or a map, checked for its container type and item count; items are read by the caller.
     *
     * @return array<int|string, mixed>
     */
    public function items(mixed $value, string $path): array
    {
        $field = $this->field($path);
        if ($value === null && $field->nullable) {
            return [];
        }
        $isList = is_array($value) && array_is_list($value);
        if (!is_array($value) || ($field->type === FieldType::ListOf && !$isList) || ($field->type === FieldType::Map && $isList && $value !== [])) {
            $this->add($path, ValidationError::TYPE, $field->type === FieldType::ListOf ? 'must be a list' : 'must be a map of named values');

            return [];
        }
        $this->checkRange($field, $path, count($value), 'items');
        if ($field->unique && count(array_unique(array_map('serialize', $value))) !== count($value)) {
            $this->add($path, ValidationError::UNIQUE, 'must not repeat items');
        }
        if ($field->type === FieldType::Map && $field->pattern !== null) {
            foreach (array_keys($value) as $key) {
                if (preg_match($field->pattern, (string) $key) !== 1) {
                    $this->add($path . '.' . $key, ValidationError::FORMAT, 'has an invalid name');
                }
            }
        }

        return $value;
    }

    /**
     * Records a problem found by a rule that compares fields.
     */
    public function add(string $path, string $code, string $message): void
    {
        $this->errors[] = new ValidationError($path, $code, $message);
    }

    /**
     * @throws InvalidConfig with every problem found so far
     */
    public function finish(string $what): void
    {
        if ($this->errors !== []) {
            throw new InvalidConfig($what, $this->errors);
        }
    }

    private function scalar(mixed $value, string $path, FieldType $expected): mixed
    {
        $field = $this->field($path);
        if ($field->type !== $expected) {
            throw new LogicException(sprintf('Field "%s" is not of type %s.', $field->key, $expected->value));
        }
        if ($value === null) {
            if (!$field->nullable) {
                $this->add($path, ValidationError::REQUIRED, 'is required');
            }

            return null;
        }
        $problem = $this->typeProblem($field, $value);
        if ($problem !== null) {
            $this->add($path, $problem[0], $problem[1]);

            return null;
        }
        if ($field->type === FieldType::Number) {
            $value = (float) $value;
        }
        if (is_int($value) || is_float($value)) {
            $this->checkRange($field, $path, $value, '');
        } elseif (is_string($value) && $field->type === FieldType::Text) {
            $this->checkRange($field, $path, mb_strlen($value), 'characters');
        }

        return $value;
    }

    /**
     * @return array{string, string}|null
     */
    private function typeProblem(Field $field, mixed $value): ?array
    {
        return match ($field->type) {
            FieldType::Integer => is_int($value) ? null : [ValidationError::TYPE, 'must be a whole number'],
            FieldType::Number => is_int($value) || is_float($value) ? null : [ValidationError::TYPE, 'must be a number'],
            FieldType::Boolean => is_bool($value) ? null : [ValidationError::TYPE, 'must be true or false'],
            FieldType::Text => !is_string($value)
                ? [ValidationError::TYPE, 'must be text']
                : ($field->pattern !== null && preg_match($field->pattern, $value) !== 1 ? [ValidationError::FORMAT, 'has an invalid format'] : null),
            FieldType::Choice => in_array($value, $field->enum ?? [], true)
                ? null
                : [ValidationError::CHOICE, 'must be one of: ' . implode(', ', $field->enum ?? [])],
            FieldType::Date => is_string($value) && Days::isValid($value) ? null : [ValidationError::FORMAT, 'must be a date as YYYY-MM-DD'],
            default => [ValidationError::TYPE, 'has an unexpected type'],
        };
    }

    private function checkRange(Field $field, string $path, int|float $value, string $noun): void
    {
        $verb = $noun === '' ? 'must be' : 'must have';
        $suffix = $noun === '' ? '' : ' ' . $noun;
        if ($field->min !== null && ($value < $field->min || ($field->exclusiveMin && $value == $field->min))) {
            $this->add($path, ValidationError::RANGE, sprintf('%s %s %s%s', $verb, $field->exclusiveMin ? 'more than' : 'at least', $field->min, $suffix));
        }
        if ($field->max !== null && $value > $field->max) {
            $this->add($path, ValidationError::RANGE, sprintf('%s at most %s%s', $verb, $field->max, $suffix));
        }
    }

    private function checkKeys(string $path, mixed $value): void
    {
        $key = $this->schema->keyForPath($path);
        if ($key === null || !is_array($value)) {
            return;
        }
        $field = $this->schema->field($key);
        if ($field->type === FieldType::Group) {
            $children = $this->schema->children($key);
            foreach ($value as $name => $child) {
                if (!array_key_exists($name, $children)) {
                    $this->add($path . '.' . $name, ValidationError::UNKNOWN, 'is not a known setting');
                    continue;
                }
                $this->checkKeys($path . '.' . $name, $child);
            }
        } elseif ($field->type === FieldType::Map || $field->type === FieldType::ListOf) {
            foreach ($value as $name => $child) {
                $this->checkKeys($path . '.' . $name, $child);
            }
        }
    }

    private function field(string $path): Field
    {
        $key = $this->schema->keyForPath($path);
        if ($key === null) {
            throw new LogicException(sprintf('No field describes "%s".', $path));
        }

        return $this->schema->field($key);
    }
}
