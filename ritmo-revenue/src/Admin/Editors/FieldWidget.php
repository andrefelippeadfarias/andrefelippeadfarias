<?php

declare(strict_types=1);

namespace Ritmo\Admin\Editors;

use InvalidArgumentException;
use Ritmo\Application\Rules\Percent;
use Ritmo\Domain\Config\Field;
use Ritmo\Domain\Config\FieldType;
use Ritmo\Domain\Config\Schema;

/**
 * How a schema field is shown in a form and how its posted text is read back (spec 05, 5.5.3).
 * A share (unit ratio) is shown and typed as a percent and converted by moving the decimal
 * point, so typing 70 stores exactly 0.7.
 */
final class FieldWidget
{
    public const NUMBER = 'number';
    public const PERCENT = 'percent';
    public const CHECKBOX = 'checkbox';
    public const TEXT = 'text';
    public const TIME = 'time';
    public const SELECT = 'select';
    public const DATE = 'date';
    public const WEEKDAYS = 'weekdays';
    public const CHOICES = 'choices';
    public const LINES = 'lines';
    public const GROUP = 'group';
    public const ITEMS = 'items';
    public const MAP = 'map';
    public const VARIANT = 'variant';

    private const TIME_KEYS = ['schedule.full_time', 'schedule.light_times.*'];

    private function __construct()
    {
    }

    public static function kind(Field $field, Schema $schema): string
    {
        return match ($field->type) {
            FieldType::Integer, FieldType::Number => $field->unit === Field::UNIT_RATIO ? self::PERCENT : self::NUMBER,
            FieldType::Boolean => self::CHECKBOX,
            FieldType::Text => in_array($field->key, self::TIME_KEYS, true) ? self::TIME : self::TEXT,
            FieldType::Choice => self::SELECT,
            FieldType::Date => self::DATE,
            FieldType::Group => self::GROUP,
            FieldType::Map => self::MAP,
            FieldType::Variant => self::VARIANT,
            FieldType::ListOf => self::listKind($field, $schema),
        };
    }

    /**
     * The text an input shows for a value ("" for null).
     */
    public static function display(Field $field, mixed $value): string
    {
        if ($value === null) {
            return '';
        }
        if ($field->unit === Field::UNIT_RATIO && (is_int($value) || is_float($value))) {
            return Percent::fromRatio((float) $value);
        }
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }
        if (is_float($value)) {
            return rtrim(rtrim(sprintf('%.6F', $value), '0'), '.');
        }

        return is_scalar($value) ? (string) $value : '';
    }

    /**
     * The value of a posted scalar field.
     *
     * @throws InvalidArgumentException with a reason code (ReasonCode) when the text does not read
     */
    public static function read(Field $field, mixed $posted): mixed
    {
        $text = is_string($posted) ? trim($posted) : '';
        if ($field->type === FieldType::Boolean) {
            return $text === '1';
        }
        if ($text === '' && ($field->nullable || $field->type === FieldType::Text && !$field->required)) {
            return $field->type === FieldType::Text && !$field->nullable ? '' : null;
        }

        return match ($field->type) {
            FieldType::Integer => self::integer($field, $text),
            FieldType::Number => self::number($field, $text),
            FieldType::Choice => self::choice($field, $text),
            FieldType::Date => preg_match('/^\d{4}-\d{2}-\d{2}$/', $text) === 1 ? $text : throw new InvalidArgumentException('date'),
            FieldType::Text => $text,
            default => throw new InvalidArgumentException('type'),
        };
    }

    /**
     * The step and the bounds an input shows, in the unit it is typed in.
     *
     * @return array{step: string, min: string, max: string}
     */
    public static function bounds(Field $field): array
    {
        $ratio = $field->unit === Field::UNIT_RATIO;
        $show = static fn (int|float|null $v): string => $v === null ? '' : ($ratio ? Percent::fromRatio((float) $v) : rtrim(rtrim(sprintf('%.6F', (float) $v), '0'), '.'));

        return ['step' => $field->type === FieldType::Integer ? '1' : ($ratio ? '0.1' : '0.01'), 'min' => $show($field->min), 'max' => $show($field->max)];
    }

    private static function listKind(Field $field, Schema $schema): string
    {
        $item = $schema->has($field->key . '.' . Schema::WILDCARD) ? $schema->field($field->key . '.' . Schema::WILDCARD) : null;
        if ($item === null) {
            return self::LINES;
        }
        if ($item->type === FieldType::Group) {
            return self::ITEMS;
        }
        if ($item->type === FieldType::Integer && $item->min === 0 && $item->max === 6) {
            return self::WEEKDAYS;
        }

        return $item->type === FieldType::Choice ? self::CHOICES : self::LINES;
    }

    private static function integer(Field $field, string $text): int
    {
        if ($field->unit === Field::UNIT_RATIO) {
            throw new InvalidArgumentException('type');
        }
        if (preg_match('/^-?\d{1,9}$/', $text) !== 1) {
            throw new InvalidArgumentException('whole_number');
        }

        return (int) $text;
    }

    private static function number(Field $field, string $text): float
    {
        if ($field->unit === Field::UNIT_RATIO) {
            try {
                return Percent::toRatio($text);
            } catch (InvalidArgumentException) {
                throw new InvalidArgumentException('number');
            }
        }
        if (preg_match('/^-?\d{1,9}(?:[.,]\d{1,6})?$/', $text) !== 1) {
            throw new InvalidArgumentException('number');
        }

        return (float) str_replace(',', '.', $text);
    }

    private static function choice(Field $field, string $text): int|string
    {
        foreach ((array) $field->enum as $allowed) {
            if ((string) $allowed === $text) {
                return $allowed;
            }
        }
        throw new InvalidArgumentException('choice');
    }
}
