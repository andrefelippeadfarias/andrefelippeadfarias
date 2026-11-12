<?php

declare(strict_types=1);

namespace Ritmo\Domain\Config;

use InvalidArgumentException;

/**
 * The single definition of one configurable field: it drives validation, the generated form
 * and import/export.
 *
 * Labels and help texts are plain English strings (this layer does not know WordPress); the
 * admin layer translates them when it renders a form.
 */
final class Field
{
    /** Units a form can show next to a value. */
    public const UNIT_PERCENT = 'percent';
    public const UNIT_DAYS = 'days';
    public const UNIT_NIGHTS = 'nights';
    public const UNIT_MONEY = 'money';
    public const UNIT_RATIO = 'ratio';

    /**
     * @param string                     $key          dotted path; "*" stands for any map key or list index
     *                                                 ("pace.band", "calendar.holidays.*.name")
     * @param mixed                      $default      value used when the field is missing on import
     * @param bool                       $required     the field has no default: the value must be given
     * @param bool                       $nullable     null is an accepted value
     * @param int|float|null             $min          lowest value (numbers), shortest length (text) or
     *                                                 fewest items (lists and maps)
     * @param int|float|null             $max          highest value, longest length or most items
     * @param bool                       $exclusiveMin the value must be greater than $min, not equal
     * @param list<int|string>|null      $enum         accepted values of a Choice
     * @param bool                       $locked       fixed by the product: the value must equal $default
     * @param string|null                $pattern      regular expression for a Text value or for the keys of a Map
     * @param list<string>               $requiredKeys keys a Map must contain
     * @param bool                       $unique       a list must not repeat items
     * @param string                     $unit         one of the UNIT_* constants, or empty
     *
     * @throws InvalidArgumentException on an inconsistent definition
     */
    public function __construct(
        public readonly string $key,
        public readonly FieldType $type,
        public readonly string $label,
        public readonly string $help = '',
        public readonly mixed $default = null,
        public readonly bool $required = false,
        public readonly bool $nullable = false,
        public readonly int|float|null $min = null,
        public readonly int|float|null $max = null,
        public readonly bool $exclusiveMin = false,
        public readonly ?array $enum = null,
        public readonly bool $locked = false,
        public readonly ?string $pattern = null,
        public readonly array $requiredKeys = [],
        public readonly bool $unique = false,
        public readonly string $unit = '',
    ) {
        if (preg_match('/^[a-z0-9_*]+(\.[a-z0-9_*]+)*$/', $key) !== 1) {
            throw new InvalidArgumentException(sprintf('Invalid field key "%s".', $key));
        }
        if ($label === '') {
            throw new InvalidArgumentException(sprintf('Field "%s" needs a label.', $key));
        }
        if ($type === FieldType::Choice && ($enum === null || $enum === [])) {
            throw new InvalidArgumentException(sprintf('Choice field "%s" needs its accepted values.', $key));
        }
        if ($min !== null && $max !== null && $min > $max) {
            throw new InvalidArgumentException(sprintf('Field "%s" has a minimum above its maximum.', $key));
        }
        if ($locked && $required) {
            throw new InvalidArgumentException(sprintf('Locked field "%s" needs a default.', $key));
        }
    }

    /**
     * The last segment of the key ("band" for "pace.band").
     */
    public function name(): string
    {
        $position = strrpos($this->key, '.');

        return $position === false ? $this->key : substr($this->key, $position + 1);
    }

    /**
     * The key of the parent field, or null for a top-level field.
     */
    public function parentKey(): ?string
    {
        $position = strrpos($this->key, '.');

        return $position === false ? null : substr($this->key, 0, $position);
    }
}
