<?php

declare(strict_types=1);

namespace Ritmo\Admin\Editors;

/**
 * The text of every option of every choice field of the rules (spec 05, 5.5.3): the option
 * value is a code and never shown. A field may word an option its own way; the others share the
 * text of the value.
 */
final class ChoiceLabels
{
    private function __construct()
    {
    }

    public static function of(string $key, int|string $value): string
    {
        $value = (string) $value;
        if (str_ends_with($key, '.restriction') || str_ends_with($key, '.open_dates.*') || str_contains($key, '.orphan_side.')) {
            return self::restriction($value);
        }

        return match ($value) {
            'weekday' => __('Weekday nights', 'ritmo-revenue'),
            'weekend' => __('Weekend nights', 'ritmo-revenue'),
            'holiday' => __('Holiday nights', 'ritmo-revenue'),
            'sent' => __('Price sent to the channels', 'ritmo-revenue'),
            'public' => __('Public price', 'ritmo-revenue'),
            'discounted' => __('Discounted price', 'ritmo-revenue'),
            'exclude' => __('Leave the night out', 'ritmo-revenue'),
            'ignore' => __('Price the night as if it were open', 'ritmo-revenue'),
            'off' => __('Off', 'ritmo-revenue'),
            'warn' => __('Only warn', 'ritmo-revenue'),
            'fix' => __('Correct it', 'ritmo-revenue'),
            'at_least' => __('Never cheaper than another room', 'ritmo-revenue'),
            'between' => __('Between two rooms', 'ritmo-revenue'),
            'premium_over_max' => __('Dearer than a group of rooms', 'ritmo-revenue'),
            'discount_band' => __('A share of the cheapest free room', 'ritmo-revenue'),
            'occupancy_switch' => __('Switch when a group fills up', 'ritmo-revenue'),
            'strategy' => __('When a sales ladder prices the night', 'ritmo-revenue'),
            'coherence' => __('When rooms are put in order', 'ritmo-revenue'),
            'audit' => __('In the audit of the whole calendar', 'ritmo-revenue'),
            'validate' => __('When the plan is checked', 'ritmo-revenue'),
            'touched' => __('Only nights the run changes', 'ritmo-revenue'),
            'horizon' => __('Every night of the horizon', 'ritmo-revenue'),
            'apply' => __('Also on sales ladder nights', 'ritmo-revenue'),
            'skip' => __('Not on sales ladder nights', 'ritmo-revenue'),
            'only' => __('Only on sales ladder nights', 'ritmo-revenue'),
            'stepped' => __('In steps', 'ritmo-revenue'),
            'proportional' => __('In proportion to the time left', 'ritmo-revenue'),
            'none' => __('No limit', 'ritmo-revenue'),
            'reference_snapshot' => __('Not above the reference price taken when the strategy started', 'ritmo-revenue'),
            'property' => __('The whole property', 'ritmo-revenue'),
            'cancelled_nights' => __('Only the cancelled nights', 'ritmo-revenue'),
            'at_booking' => __('Price when the booking was made', 'ritmo-revenue'),
            'current' => __('Price now', 'ritmo-revenue'),
            'cheapest' => __('Cheapest room', 'ritmo-revenue'),
            'comparable' => __('Comparable room', 'ritmo-revenue'),
            default => $value,
        };
    }

    private static function restriction(string $value): string
    {
        return match ($value) {
            'none' => __('Open for arrival and departure', 'ritmo-revenue'),
            'no_checkin' => __('Closed to arrival', 'ritmo-revenue'),
            'no_checkout' => __('Closed to departure', 'ritmo-revenue'),
            'no_checkin_checkout' => __('Closed to arrival and departure', 'ritmo-revenue'),
            default => $value,
        };
    }
}
