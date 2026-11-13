<?php

declare(strict_types=1);

namespace Ritmo\Admin\Editors;

use Ritmo\Admin\Components\Money;
use Ritmo\Application\Rules\Percent;
use Ritmo\Domain\Config\Field;
use Ritmo\Domain\Config\FieldType;
use Ritmo\Domain\Config\Schema;

/**
 * A rule value as words for the review and the read-only views: never JSON, never a code
 * ("75%", "Friday and Saturday nights", "R$ 450", "On"). A whole list item is named by its
 * name, label or id.
 */
final class ValueText
{
    private const UNITS_WITH_NUMBER = [Field::UNIT_DAYS, Field::UNIT_NIGHTS, 'minutes', 'hours', 'seconds', 'runs'];

    private function __construct()
    {
    }

    public static function of(?string $key, mixed $value, Schema $schema, string $currency): string
    {
        if ($value === null) {
            return __('not set', 'ritmo-revenue');
        }
        $field = $key !== null && $schema->has($key) ? $schema->field($key) : null;
        if (is_bool($value)) {
            return $value ? __('On', 'ritmo-revenue') : __('Off', 'ritmo-revenue');
        }
        if ($field !== null && $field->type === FieldType::Choice) {
            return ChoiceLabels::of($field->key, is_int($value) ? $value : (string) $value);
        }
        if ((is_int($value) || is_float($value)) && $field !== null) {
            return self::number($field, $value, $currency);
        }
        if (is_array($value)) {
            return self::list($field, $value, $schema, $currency);
        }

        return is_scalar($value) ? (string) $value : '';
    }

    public static function weekday(int $day): string
    {
        return [
            __('Monday', 'ritmo-revenue'), __('Tuesday', 'ritmo-revenue'), __('Wednesday', 'ritmo-revenue'), __('Thursday', 'ritmo-revenue'),
            __('Friday', 'ritmo-revenue'), __('Saturday', 'ritmo-revenue'), __('Sunday', 'ritmo-revenue'),
        ][$day] ?? (string) $day;
    }

    /**
     * The short name of a day of the week (0 = Monday), for the head of a month.
     */
    public static function weekdayShort(int $day): string
    {
        return [
            /* translators: short Monday */
            __('Mon', 'ritmo-revenue'),
            /* translators: short Tuesday */
            __('Tue', 'ritmo-revenue'),
            /* translators: short Wednesday */
            __('Wed', 'ritmo-revenue'),
            /* translators: short Thursday */
            __('Thu', 'ritmo-revenue'),
            /* translators: short Friday */
            __('Fri', 'ritmo-revenue'),
            /* translators: short Saturday */
            __('Sat', 'ritmo-revenue'),
            /* translators: short Sunday */
            __('Sun', 'ritmo-revenue'),
        ][$day] ?? (string) $day;
    }

    private static function number(Field $field, int|float $value, string $currency): string
    {
        $plain = rtrim(rtrim(sprintf('%.4F', (float) $value), '0'), '.');

        return match (true) {
            $field->unit === Field::UNIT_RATIO => Percent::fromRatio((float) $value) . '%',
            $field->unit === Field::UNIT_PERCENT => $plain . '%',
            $field->unit === Field::UNIT_MONEY => Money::format((float) $value, $currency),
            in_array($field->unit, self::UNITS_WITH_NUMBER, true) => sprintf('%s %s', $plain, self::unit($field->unit)),
            default => $plain,
        };
    }

    /**
     * @param array<int|string, mixed> $value
     */
    private static function list(?Field $field, array $value, Schema $schema, string $currency): string
    {
        if ($value === []) {
            return __('none', 'ritmo-revenue');
        }
        $itemKey = $field === null ? null : $field->key . '.' . Schema::WILDCARD;
        $item = $itemKey !== null && $schema->has($itemKey) ? $schema->field($itemKey) : null;
        if ($item !== null && $item->type === FieldType::Integer && $item->min === 0 && $item->max === 6) {
            return implode(', ', array_map(static fn (mixed $d): string => self::weekday((int) $d), $value));
        }
        if (!array_is_list($value) || ($field !== null && $field->type === FieldType::Group)) {
            foreach (['name', 'label', 'id'] as $name) {
                if (is_string($value[$name] ?? null) && $value[$name] !== '') {
                    return $value[$name];
                }
            }
            /* translators: %d: number of settings in the item */
            return sprintf(_n('%d setting', '%d settings', count($value), 'ritmo-revenue'), count($value));
        }
        $texts = array_map(static fn (mixed $v): string => is_array($v) ? self::list(null, $v, $schema, $currency) : ($item === null ? (is_scalar($v) ? (string) $v : '') : self::of($itemKey, $v, $schema, $currency)), $value);

        return implode(', ', $texts);
    }

    private static function unit(string $unit): string
    {
        return match ($unit) {
            Field::UNIT_DAYS => __('days', 'ritmo-revenue'),
            Field::UNIT_NIGHTS => __('nights', 'ritmo-revenue'),
            'minutes' => __('minutes', 'ritmo-revenue'),
            'hours' => __('hours', 'ritmo-revenue'),
            'seconds' => __('seconds', 'ritmo-revenue'),
            default => __('runs', 'ritmo-revenue'),
        };
    }
}
