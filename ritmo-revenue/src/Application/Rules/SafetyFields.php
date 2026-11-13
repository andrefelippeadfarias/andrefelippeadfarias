<?php

declare(strict_types=1);

namespace Ritmo\Application\Rules;

/**
 * The rule-set fields that are safety limits (spec 05, 3.4): changing any of them needs the
 * capability ritmo_manage_safety and a typed confirmation of the new value.
 */
final class SafetyFields
{
    /** Exact keys, or "<section>.*" for every field of a section. */
    public const KEYS = [
        'safety.weekly_listing_change_max',
        'safety.never_raise_today',
        'floors.fraction_of_reference_min',
        'write.listing_changes_enabled',
        'modes.autonomous_enabled',
        'quota.*',
    ];

    private function __construct()
    {
    }

    public static function covers(?string $key): bool
    {
        if ($key === null) {
            return false;
        }
        foreach (self::KEYS as $safety) {
            if ($key === $safety || (str_ends_with($safety, '.*') && str_starts_with($key, substr($safety, 0, -1)))) {
                return true;
            }
        }

        return false;
    }

    /**
     * The changes that touch a safety field.
     *
     * @param list<FieldChange> $changes
     *
     * @return list<FieldChange>
     */
    public static function of(array $changes): array
    {
        return array_values(array_filter($changes, static fn (FieldChange $c): bool => self::covers($c->key)));
    }

    /**
     * What a person types to confirm safety changes: the new values as the form shows them, in
     * order, separated by spaces (a share as a percent: 0.7 is "70"; on/off as 1/0). Never a
     * translated word.
     *
     * @param list<FieldChange> $changes safety changes
     */
    public static function confirmation(array $changes): string
    {
        return implode(' ', array_map(static fn (FieldChange $c): string => self::shown($c), $changes));
    }

    private static function shown(FieldChange $change): string
    {
        $value = $change->after;

        return match (true) {
            is_bool($value) => $value ? '1' : '0',
            is_float($value) && in_array($change->key, ['safety.weekly_listing_change_max', 'floors.fraction_of_reference_min'], true) => Percent::fromRatio($value),
            is_int($value), is_float($value) => rtrim(rtrim(sprintf('%.6F', $value), '0'), '.'),
            default => is_scalar($value) ? (string) $value : '',
        };
    }
}
