<?php

declare(strict_types=1);

namespace Ritmo\Admin\Editors;

use InvalidArgumentException;
use Ritmo\Application\Rules\RuleDiff;
use Ritmo\Domain\Config\Field;
use Ritmo\Domain\Config\FieldType;
use Ritmo\Domain\Config\Schema;

/**
 * Reads a posted rules form (fields named "f[<path>]") into the draft rules, strictly: a value
 * that does not read is reported with its path and keeps the value it had; values that read go
 * into the draft even when a rule across fields fails (that is checked at review). Only the
 * fields of the form's scope change; fields absent from the post keep their value.
 *
 * Conventions of the posted form: a checkbox posts a hidden "0" before its "1"; a list of
 * checkboxes posts "__present" with the ticked values; a list of plain values is one text with a
 * value per line; list items post "_remove" to be dropped, and the empty item "__new" is added
 * only with "_add".
 */
final class RuleFormReader
{
    public const PRESENT = '__present';
    public const NEW_ITEM = '__new';
    public const REMOVE = '_remove';
    public const ADD = '_add';

    /** @var list<array{path: string, code: string}> */
    private array $errors = [];

    private function __construct(private readonly Schema $schema, private readonly ?FormScope $scope)
    {
    }

    /**
     * @param FormScope|null       $scope  null: every field of the schema (the relation and band forms)
     * @param array<string, mixed> $rules  the draft rules
     * @param array<string, mixed> $posted the "f" array of the form, unslashed
     */
    public static function read(Schema $schema, ?FormScope $scope, array $rules, array $posted): FormResult
    {
        $reader = new self($schema, $scope);
        $read = $rules;
        $roots = $scope?->roots() ?? array_map(static fn ($f): string => $f->key, array_values($schema->children('')));
        foreach ($roots as $root) {
            if (array_key_exists($root, $posted) && $schema->has($root)) {
                $read[$root] = $reader->value($root, $root, $rules[$root] ?? null, $posted[$root]);
            }
        }
        $read = $schema->withDefaults($read);
        $touched = array_map(static fn ($c): string => $c->path, RuleDiff::between($rules, $read, $schema));

        return new FormResult($read, $reader->errors, $touched);
    }

    private function value(string $key, string $path, mixed $current, mixed $posted): mixed
    {
        $field = $this->schema->field($key);
        $kind = FieldWidget::kind($field, $this->schema);
        if (in_array($kind, [FieldWidget::GROUP, FieldWidget::MAP], true)) {
            return is_array($posted) ? $this->container($field, $kind, $path, is_array($current) ? $current : [], $posted) : $current;
        }
        if (($this->scope !== null && !$this->scope->holds($key)) || $field->locked || $kind === FieldWidget::VARIANT) {
            return $current;
        }

        return match ($kind) {
            FieldWidget::ITEMS => is_array($posted) ? $this->items($key, $path, is_array($current) ? $current : [], $posted) : $current,
            FieldWidget::WEEKDAYS, FieldWidget::CHOICES => $this->ticked($key, $path, $current, $posted, $kind),
            FieldWidget::LINES => is_string($posted) ? $this->lines($key, $path, $current, $posted) : $current,
            default => $this->scalar($field, $path, $current, $posted),
        };
    }

    /**
     * @param array<int|string, mixed> $current
     * @param array<int|string, mixed> $posted
     *
     * @return array<int|string, mixed>
     */
    private function container(Field $field, string $kind, string $path, array $current, array $posted): array
    {
        $read = $current;
        if ($kind === FieldWidget::GROUP) {
            foreach ($this->schema->children($field->key) as $name => $child) {
                if (array_key_exists($name, $posted)) {
                    $read[$name] = $this->value($child->key, $path . '.' . $name, $current[$name] ?? null, $posted[$name]);
                }
            }

            return $read;
        }
        foreach ($posted as $name => $value) {
            if (!str_starts_with((string) $name, '__')) {
                $read[$name] = $this->value($field->key . '.' . Schema::WILDCARD, $path . '.' . $name, $current[$name] ?? null, $value);
            }
        }

        return $read;
    }

    /**
     * @param array<int|string, mixed> $current
     * @param array<int|string, mixed> $posted
     *
     * @return list<mixed>
     */
    private function items(string $key, string $path, array $current, array $posted): array
    {
        $items = [];
        foreach ($posted as $index => $item) {
            if (!is_array($item)) {
                continue;
            }
            $isNew = (string) $index === self::NEW_ITEM;
            if (($isNew && ($item[self::ADD] ?? '') !== '1') || (!$isNew && ($item[self::REMOVE] ?? '') === '1')) {
                continue;
            }
            unset($item[self::ADD], $item[self::REMOVE]);
            $items[] = $this->value($key . '.' . Schema::WILDCARD, $path . '.' . count($items), $isNew ? [] : ($current[$index] ?? []), $item);
        }

        return $items;
    }

    private function ticked(string $key, string $path, mixed $current, mixed $posted, string $kind): mixed
    {
        if (!is_array($posted) || !array_key_exists(self::PRESENT, $posted)) {
            return $current;
        }
        unset($posted[self::PRESENT]);
        $item = $this->schema->field($key . '.' . Schema::WILDCARD);
        $values = [];
        foreach ($posted as $value) {
            try {
                $values[] = FieldWidget::read($item, is_string($value) ? $value : '');
            } catch (InvalidArgumentException $e) {
                $this->errors[] = ['path' => $path, 'code' => $e->getMessage()];

                return $current;
            }
        }
        $values = array_values(array_unique($values, SORT_REGULAR));
        if ($kind === FieldWidget::WEEKDAYS) {
            sort($values);
        }

        return $values;
    }

    private function lines(string $key, string $path, mixed $current, string $posted): mixed
    {
        $item = $this->schema->field($key . '.' . Schema::WILDCARD);
        $values = [];
        foreach (preg_split('/\R/', $posted) ?: [] as $line) {
            if (trim($line) === '') {
                continue;
            }
            try {
                $values[] = FieldWidget::read($item, $line);
            } catch (InvalidArgumentException $e) {
                $this->errors[] = ['path' => $path, 'code' => $e->getMessage()];

                return $current;
            }
        }

        return $values;
    }

    private function scalar(Field $field, string $path, mixed $current, mixed $posted): mixed
    {
        if (!is_string($posted)) {
            return $current;
        }
        try {
            return FieldWidget::read($field, $posted);
        } catch (InvalidArgumentException $e) {
            $this->errors[] = ['path' => $path, 'code' => $e->getMessage()];

            return $current;
        }
    }
}
