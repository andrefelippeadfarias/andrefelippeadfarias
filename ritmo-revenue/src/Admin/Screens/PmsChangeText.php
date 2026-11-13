<?php

declare(strict_types=1);

namespace Ritmo\Admin\Screens;

use Ritmo\Application\Approval\PlanEntries;
use Ritmo\Domain\Write\State\ChangeField;

/**
 * A change of the PMS calendar in words, one sentence per field: "Minimum stay: 1 → 2 nights",
 * "Arrival and departure: open → closed to arrival". Never a field name or a raw value.
 */
final class PmsChangeText
{
    private function __construct()
    {
    }

    /**
     * @param array<string, int|float|string> $fields the fields set, with the values read before ("before:<field>")
     *
     * @return list<string>
     */
    public static function lines(array $fields): array
    {
        $out = [];
        foreach ($fields as $field => $value) {
            $field = (string) $field;
            if (str_starts_with($field, PlanEntries::BEFORE)) {
                continue;
            }
            $before = $fields[PlanEntries::BEFORE . $field] ?? 'none';
            $out[] = sprintf(
                /* translators: 1: what changes (minimum stay, arrival and departure...), 2: value before, 3: value after */
                __('%1$s: %2$s → %3$s', 'ritmo-revenue'),
                self::label($field),
                self::value($field, $before),
                self::value($field, $value)
            );
        }

        return $out;
    }

    private static function label(string $field): string
    {
        if (str_starts_with($field, ChangeField::PMS_CHANNEL_LIMIT)) {
            return sprintf(
                /* translators: %s: name of a sales channel */
                __('Bookings allowed on %s', 'ritmo-revenue'),
                substr($field, strlen(ChangeField::PMS_CHANNEL_LIMIT))
            );
        }

        return match ($field) {
            ChangeField::PMS_MIN_STAY => __('Minimum stay', 'ritmo-revenue'),
            ChangeField::PMS_MAX_STAY => __('Maximum stay', 'ritmo-revenue'),
            ChangeField::PMS_RESTRICTION => __('Arrival and departure', 'ritmo-revenue'),
            ChangeField::PMS_AVAILABLE => __('Rooms available', 'ritmo-revenue'),
            default => __('Calendar setting', 'ritmo-revenue'),
        };
    }

    private static function value(string $field, int|float|string $value): string
    {
        if ($value === 'none') {
            return $field === ChangeField::PMS_RESTRICTION ? __('open', 'ritmo-revenue') : __('not set', 'ritmo-revenue');
        }
        if ($field === ChangeField::PMS_RESTRICTION) {
            return match ((string) $value) {
                'no_checkin' => __('closed to arrival', 'ritmo-revenue'),
                'no_checkout' => __('closed to departure', 'ritmo-revenue'),
                'no_checkin_checkout' => __('closed to arrival and departure', 'ritmo-revenue'),
                default => __('open', 'ritmo-revenue'),
            };
        }
        if ($field === ChangeField::PMS_MIN_STAY || $field === ChangeField::PMS_MAX_STAY) {
            /* translators: %s: number of nights */
            return sprintf(_n('%s night', '%s nights', (int) $value, 'ritmo-revenue'), number_format_i18n((int) $value));
        }

        return number_format_i18n((int) $value);
    }
}
