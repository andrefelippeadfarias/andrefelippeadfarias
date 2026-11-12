<?php

declare(strict_types=1);

namespace Ritmo\Domain\Config;

use InvalidArgumentException;
use OutOfBoundsException;

/**
 * An ordered set of field definitions forming a tree by their dotted keys.
 *
 * The rule set schema ({@see Schema::ruleSet()}) is the single definition of everything a
 * customer can configure: the validator, the generated forms and import/export all read it.
 */
final class Schema
{
    /** Version of the rule set schema; raised when a stored rule set needs migrating. */
    public const VERSION = 1;

    /** Key segment standing for any map key or list index. */
    public const WILDCARD = '*';

    private static ?self $ruleSet = null;

    /** @var array<string, Field> */
    private array $fields = [];

    /** @var array<string, list<string>> parent key ("" for the top level) => child keys */
    private array $childKeys = ['' => []];

    /**
     * @param list<Field> $fields parents before their children
     *
     * @throws InvalidArgumentException when the definitions do not form a consistent tree
     */
    public function __construct(array $fields)
    {
        foreach ($fields as $field) {
            $this->add($field);
        }
        $this->assertContainersHaveChildren();
    }

    /**
     * The schema of the rule set (built once).
     */
    public static function ruleSet(): self
    {
        return self::$ruleSet ??= new self(RuleSetFields::all());
    }

    /**
     * Every field by key, in definition order (parents before children).
     *
     * @return array<string, Field>
     */
    public function fields(): array
    {
        return $this->fields;
    }

    public function has(string $key): bool
    {
        return isset($this->fields[$key]);
    }

    /**
     * @throws OutOfBoundsException when no field has that key
     */
    public function field(string $key): Field
    {
        if (!isset($this->fields[$key])) {
            throw new OutOfBoundsException(sprintf('Unknown configuration field "%s".', $key));
        }

        return $this->fields[$key];
    }

    /**
     * The direct children of a field by name, in definition order. Pass "" for the top level.
     *
     * @return array<int|string, Field> numeric names ("7", "60") come back as integer keys
     */
    public function children(string $key): array
    {
        $children = [];
        foreach ($this->childKeys[$key] ?? [] as $childKey) {
            $children[$this->fields[$childKey]->name()] = $this->fields[$childKey];
        }

        return $children;
    }

    /**
     * The field key a concrete path falls under, or null when the path matches no field.
     * "calendar.holidays.2.end" resolves to "calendar.holidays.*.end".
     */
    public function keyForPath(string $path): ?string
    {
        $key = '';
        foreach (explode('.', $path) as $segment) {
            $parentType = $key === '' ? FieldType::Group : $this->fields[$key]->type;
            $name = $parentType === FieldType::Group ? $segment : self::WILDCARD;
            $key = $key === '' ? $name : $key . '.' . $name;
            if (!isset($this->fields[$key])) {
                return null;
            }
        }

        return $key;
    }

    /**
     * The complete default configuration.
     *
     * @return array<string, mixed>
     */
    public function defaults(): array
    {
        return $this->groupDefaults('');
    }

    /**
     * The data with every missing field that has a default filled in, at any depth. Values that
     * are present are kept as they are (valid or not), and so are unknown keys: validation is
     * the validator's job.
     *
     * @param array<int|string, mixed> $data
     *
     * @return array<int|string, mixed>
     */
    public function withDefaults(array $data): array
    {
        return $this->fillGroup('', $data);
    }

    private function add(Field $field): void
    {
        if (isset($this->fields[$field->key])) {
            throw new InvalidArgumentException(sprintf('Field "%s" is defined twice.', $field->key));
        }

        $parentKey = $field->parentKey() ?? '';
        $parentType = FieldType::Group;
        if ($parentKey !== '') {
            if (!isset($this->fields[$parentKey])) {
                throw new InvalidArgumentException(sprintf('Field "%s" is defined before its parent.', $field->key));
            }
            $parentType = $this->fields[$parentKey]->type;
        }
        if (!$parentType->isContainer()) {
            throw new InvalidArgumentException(sprintf('Field "%s" has a parent that cannot hold fields.', $field->key));
        }
        if (($parentType === FieldType::Group) === ($field->name() === self::WILDCARD)) {
            throw new InvalidArgumentException(sprintf(
                'Field "%s": the child of a group is named, the child of a map or list is "%s".',
                $field->key,
                self::WILDCARD
            ));
        }

        $this->fields[$field->key] = $field;
        $this->childKeys[$parentKey][] = $field->key;
        $this->childKeys[$field->key] = [];
    }

    private function assertContainersHaveChildren(): void
    {
        foreach ($this->fields as $key => $field) {
            if ($field->type->isContainer() && $this->childKeys[$key] === []) {
                throw new InvalidArgumentException(sprintf('Container field "%s" has no children.', $key));
            }
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function groupDefaults(string $key): array
    {
        $defaults = [];
        foreach ($this->children($key) as $name => $child) {
            if (!$child->required) {
                $defaults[$name] = $child->type === FieldType::Group ? $this->groupDefaults($child->key) : $child->default;
            }
        }

        return $defaults;
    }

    private function fill(Field $field, mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        if ($field->type === FieldType::Group) {
            return $this->fillGroup($field->key, $value);
        }
        if ($field->type === FieldType::Map || $field->type === FieldType::ListOf) {
            $item = $this->fields[$field->key . '.' . self::WILDCARD];

            return array_map(fn (mixed $entry): mixed => $this->fill($item, $entry), $value);
        }

        return $value;
    }

    /**
     * @param array<int|string, mixed> $value
     *
     * @return array<int|string, mixed>
     */
    private function fillGroup(string $key, array $value): array
    {
        foreach ($this->children($key) as $name => $child) {
            if (array_key_exists($name, $value)) {
                $value[$name] = $this->fill($child, $value[$name]);
            } elseif (!$child->required) {
                $value[$name] = $child->type === FieldType::Group ? $this->groupDefaults($child->key) : $child->default;
            }
        }

        return $value;
    }
}
