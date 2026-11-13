<?php

declare(strict_types=1);

namespace Ritmo\Admin\Editors;

use Ritmo\Admin\Components\DomainText;
use Ritmo\Application\Rules\RuleDiff;
use Ritmo\Domain\Config\Field;
use Ritmo\Domain\Config\Schema;

/**
 * Turns a schema field and its value into what templates/admin/editors/field draws (spec 05,
 * 5.5.3): label, help, allowed range, default, the "Changed" mark, the control and its options,
 * and the children of groups, maps and list items. Plain values only; the template escapes.
 */
final class FieldView
{
    private const ROOM_KEYS = ['target', 'reference', 'low_reference', 'high_reference', 'room'];

    private function __construct()
    {
    }

    /**
     * @return array<string, mixed>|null null when nothing under the field is in the form's part
     */
    public static function node(Field $field, string $path, mixed $value, FieldContext $ctx): ?array
    {
        $kind = FieldWidget::kind($field, $ctx->schema);
        $base = [
            'kind' => $kind,
            'key' => $field->key,
            'id' => FieldPath::anchor($path),
            'name' => self::name($ctx->nameRoot, $path),
            'label' => DomainText::t($field->label),
            'help' => DomainText::t($field->help),
            'error' => $ctx->errors[$path] ?? null,
            'disabled' => $ctx->readOnly || $field->locked,
            'locked' => $field->locked,
            'hook' => 'field-' . str_replace('.', '-', $path),
        ];
        if (in_array($kind, [FieldWidget::GROUP, FieldWidget::MAP], true)) {
            $children = self::children($field, $kind, $path, is_array($value) ? $value : [], $ctx);

            return $children === [] ? null : $base + ['children' => $children];
        }
        if (!$ctx->holds($field->key)) {
            return null;
        }
        $inForce = self::at($ctx->inForce, $path);
        $base += [
            'changed' => $ctx->scope !== null && !RuleDiff::same($inForce, $value),
            'default_text' => in_array($kind, [FieldWidget::ITEMS, FieldWidget::VARIANT], true) ? '' : ValueText::of($field->key, $field->default, $ctx->schema, $ctx->currency),
            'value_text' => ValueText::of($field->key, $value, $ctx->schema, $ctx->currency),
        ];

        // A list of room codes shows as room checkboxes: its own kind replaces the base one.
        return array_replace($base, match ($kind) {
            FieldWidget::NUMBER, FieldWidget::PERCENT => ['value' => FieldWidget::display($field, $value), 'unit' => self::unit($field, $ctx->currency)] + FieldWidget::bounds($field),
            FieldWidget::CHECKBOX => ['checked' => $value === true],
            FieldWidget::SELECT => ['options' => self::options($field, $value)],
            FieldWidget::WEEKDAYS => ['options' => self::weekdays(is_array($value) ? $value : [])],
            FieldWidget::CHOICES => ['options' => self::choices($ctx->schema->field($field->key . '.' . Schema::WILDCARD), is_array($value) ? $value : [])],
            FieldWidget::LINES => self::lines($field, is_array($value) ? $value : [], $ctx),
            FieldWidget::ITEMS => self::items($field, $path, is_array($value) ? $value : [], $ctx),
            FieldWidget::VARIANT => [],
            default => self::text($field, $value, $ctx),
        });
    }

    /**
     * The nodes of a list of top-level fields of the rules.
     *
     * @param list<string>         $keys
     * @param array<string, mixed> $rules
     *
     * @return list<array<string, mixed>>
     */
    public static function nodes(array $keys, array $rules, FieldContext $ctx): array
    {
        $nodes = [];
        foreach ($keys as $key) {
            if ($ctx->schema->has($key)) {
                $node = self::node($ctx->schema->field($key), $key, $rules[$key] ?? null, $ctx);
                if ($node !== null) {
                    $nodes[] = $node;
                }
            }
        }

        return $nodes;
    }

    public static function name(string $root, string $path): string
    {
        return $root . '[' . str_replace('.', '][', $path) . ']';
    }

    /**
     * @param array<int|string, mixed> $value
     *
     * @return list<array<string, mixed>>
     */
    private static function children(Field $field, string $kind, string $path, array $value, FieldContext $ctx): array
    {
        $nodes = [];
        if ($kind === FieldWidget::GROUP) {
            foreach ($ctx->schema->children($field->key) as $name => $child) {
                $node = self::node($child, $path . '.' . $name, $value[$name] ?? null, $ctx);
                if ($node !== null) {
                    $nodes[] = $node + ['advanced' => RulesLayout::advanced($child->key)];
                }
            }

            return $nodes;
        }
        $entry = $ctx->schema->field($field->key . '.' . Schema::WILDCARD);
        foreach ($value as $name => $item) {
            $node = self::node($entry, $path . '.' . $name, $item, $ctx);
            if ($node !== null) {
                $nodes[] = ['label' => (string) $name] + $node;
            }
        }

        return $nodes;
    }

    /**
     * @param array<int|string, mixed> $value
     *
     * @return array<string, mixed>
     */
    private static function items(Field $field, string $path, array $value, FieldContext $ctx): array
    {
        $entry = $ctx->schema->field($field->key . '.' . Schema::WILDCARD);
        $items = [];
        foreach (array_values($value) as $index => $item) {
            $node = self::node($entry, $path . '.' . $index, $item, $ctx);
            $items[] = [
                'legend' => self::itemLegend($item, $index),
                'node' => $node,
                'remove_name' => self::name($ctx->nameRoot, $path . '.' . $index . '.' . RuleFormReader::REMOVE),
            ];
        }
        $blank = self::node($entry, $path . '.' . RuleFormReader::NEW_ITEM, [], new FieldContext($ctx->schema, $ctx->scope, $ctx->inForce, $ctx->rooms, $ctx->currency, [], $ctx->readOnly, $ctx->nameRoot));

        return ['items' => $items, 'new' => $blank, 'add_name' => self::name($ctx->nameRoot, $path . '.' . RuleFormReader::NEW_ITEM . '.' . RuleFormReader::ADD), 'max' => $field->max];
    }

    /**
     * @param array<string, mixed>|mixed $item
     */
    private static function itemLegend(mixed $item, int $index): string
    {
        foreach (['name', 'label', 'id'] as $name) {
            if (is_array($item) && is_string($item[$name] ?? null) && $item[$name] !== '') {
                return $item[$name];
            }
        }

        return (string) ($index + 1);
    }

    /**
     * @return array<string, mixed>
     */
    private static function text(Field $field, mixed $value, FieldContext $ctx): array
    {
        $last = substr((string) strrchr('.' . $field->key, '.'), 1);
        if (in_array($last, self::ROOM_KEYS, true) && $ctx->rooms !== []) {
            $options = [['value' => '', 'label' => __('Choose a room', 'ritmo-revenue'), 'selected' => $value === null || $value === '']];
            foreach ($ctx->rooms as $code => $name) {
                $options[] = ['value' => (string) $code, 'label' => $name, 'selected' => $value === $code];
            }

            return ['kind' => FieldWidget::SELECT, 'options' => $options];
        }

        return ['value' => FieldWidget::display($field, $value), 'maxlength' => $field->max === null ? '' : (string) (int) $field->max];
    }

    /**
     * @param array<int|string, mixed> $value
     *
     * @return array<string, mixed>
     */
    private static function lines(Field $field, array $value, FieldContext $ctx): array
    {
        $item = $ctx->schema->field($field->key . '.' . Schema::WILDCARD);
        if (str_ends_with($field->key, 'rooms') && $ctx->rooms !== []) {
            $options = [];
            foreach ($ctx->rooms as $code => $name) {
                $options[] = ['value' => (string) $code, 'label' => $name, 'checked' => in_array($code, $value, true)];
            }

            return ['kind' => FieldWidget::CHOICES, 'options' => $options];
        }

        return ['value' => implode("\n", array_map(static fn (mixed $v): string => FieldWidget::display($item, $v), $value))];
    }

    /**
     * @return list<array{value: string, label: string, selected: bool}>
     */
    private static function options(Field $field, mixed $value): array
    {
        $options = [];
        if ($field->nullable) {
            $options[] = ['value' => '', 'label' => __('Not set', 'ritmo-revenue'), 'selected' => $value === null];
        }
        foreach ((array) $field->enum as $option) {
            $options[] = ['value' => (string) $option, 'label' => ChoiceLabels::of($field->key, $option), 'selected' => $value !== null && (string) $value === (string) $option];
        }

        return $options;
    }

    /**
     * @param array<int|string, mixed> $value
     *
     * @return list<array{value: string, label: string, checked: bool}>
     */
    private static function weekdays(array $value): array
    {
        $options = [];
        for ($day = 0; $day < 7; ++$day) {
            $options[] = ['value' => (string) $day, 'label' => ValueText::weekday($day), 'checked' => in_array($day, $value, true)];
        }

        return $options;
    }

    /**
     * @param array<int|string, mixed> $value
     *
     * @return list<array{value: string, label: string, checked: bool}>
     */
    private static function choices(Field $item, array $value): array
    {
        return array_map(static fn (int|string $option): array => ['value' => (string) $option, 'label' => ChoiceLabels::of($item->key, $option), 'checked' => in_array($option, $value, true)], (array) $item->enum);
    }

    private static function unit(Field $field, string $currency): string
    {
        return match ($field->unit) {
            Field::UNIT_RATIO, Field::UNIT_PERCENT => '%',
            Field::UNIT_MONEY => $currency,
            Field::UNIT_DAYS => __('days', 'ritmo-revenue'),
            Field::UNIT_NIGHTS => __('nights', 'ritmo-revenue'),
            'minutes' => __('minutes', 'ritmo-revenue'),
            'hours' => __('hours', 'ritmo-revenue'),
            'seconds' => __('seconds', 'ritmo-revenue'),
            'runs' => __('runs', 'ritmo-revenue'),
            default => '',
        };
    }

    /**
     * @param array<int|string, mixed> $data
     */
    private static function at(array $data, string $path): mixed
    {
        foreach (explode('.', $path) as $segment) {
            if (!is_array($data) || !array_key_exists($segment, $data)) {
                return null;
            }
            $data = $data[$segment];
        }

        return $data;
    }
}
