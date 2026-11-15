<?php

declare(strict_types=1);

namespace Ritmo\Domain\Ai;

use Ritmo\Domain\Config\Field;
use Ritmo\Domain\Config\FieldType;
use Ritmo\Domain\Config\Schema;

/**
 * The rule settings a draft of the assistant may propose: numbers, switches and choices of the
 * rule schema, never a locked field and never anything of safety, writing (prices or the stay
 * rules in the PMS), the plan validator, modes, quotas, approvals, the schedule or the alerts. The catalogue text (one line per field) is a stable
 * system block of the request prompt.
 */
final class RuleCatalogue
{
    /** Sections the assistant never proposes, whatever their fields. */
    public const DENIED_PREFIXES = [
        'safety.', 'write.', 'modes.', 'quota.', 'approval.', 'third_party.', 'verify.', 'waves.', 'schedule.',
        'light_run.', 'alerts.', 'reports.', 'stay.write.', 'plan.validator.', 'plan.payload.', 'market.csv.',
    ];

    /** Single fields that are safety limits outside those sections. */
    public const DENIED_KEYS = ['floors.fraction_of_reference_min', 'plan.horizon_nights'];

    private const TYPES = [FieldType::Integer, FieldType::Number, FieldType::Boolean, FieldType::Choice];

    public function __construct(private readonly Schema $schema)
    {
    }

    public static function forRules(): self
    {
        return new self(Schema::ruleSet());
    }

    /**
     * The field of a concrete dotted path ("calendar.holidays.2.floor"), or null when the
     * assistant may not propose it.
     */
    public function fieldFor(string $path): ?Field
    {
        if (preg_match('/^[a-z0-9_]+(\.[A-Za-z0-9_-]+){0,8}$/', $path) !== 1) {
            return null;
        }
        $key = $this->schema->keyForPath($path);
        if ($key === null) {
            return null;
        }
        $field = $this->schema->field($key);

        return self::allows($field) ? $field : null;
    }

    /**
     * @return list<Field>
     */
    public function fields(): array
    {
        return array_values(array_filter($this->schema->fields(), self::allows(...)));
    }

    /**
     * One line per field: key | type | unit | range or choices | label.
     */
    public function text(): string
    {
        $lines = ['Catalogue of the rule settings a draft may change (key | type | unit | allowed values | label):'];
        foreach ($this->fields() as $field) {
            $values = match ($field->type) {
                FieldType::Boolean => 'yes or no',
                FieldType::Choice => implode(', ', array_map('strval', $field->enum ?? [])),
                default => self::range($field),
            };
            $lines[] = implode(' | ', [$field->key, $field->type->value, $field->unit === '' ? '-' : $field->unit, $values, $field->label]);
        }

        return implode("\n", $lines);
    }

    public static function allows(Field $field): bool
    {
        if ($field->locked || !in_array($field->type, self::TYPES, true) || in_array($field->key, self::DENIED_KEYS, true)) {
            return false;
        }
        foreach (self::DENIED_PREFIXES as $prefix) {
            if (str_starts_with($field->key, $prefix)) {
                return false;
            }
        }

        return true;
    }

    private static function range(Field $field): string
    {
        $min = $field->min === null ? '' : (string) $field->min;
        $max = $field->max === null ? '' : (string) $field->max;

        return $min === '' && $max === '' ? 'any' : ($min === '' ? 'up to ' . $max : ($max === '' ? 'from ' . $min : $min . ' to ' . $max));
    }
}
