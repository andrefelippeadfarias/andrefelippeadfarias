<?php

declare(strict_types=1);

namespace Ritmo\Admin\Components;

use Ritmo\Domain\Support\Num;

/**
 * Percentages in words: the change between two amounts ("+4.2%", "−3.0%") and a floor or
 * ceiling stated against the listing base ("20% below the base").
 */
final class Percent
{
    private const MINUS = "\u{2212}";

    private function __construct()
    {
    }

    /**
     * (after − before) / before × 100 with one decimal and the sign always shown; "new" when
     * there was nothing before.
     */
    public static function change(float $before, float $after): string
    {
        if (abs($before) < 0.0000001) {
            return __('new', 'ritmo-revenue');
        }
        $value = Num::roundHalfEven(($after - $before) / $before * 100, 1);
        $sign = $value > 0 ? '+' : ($value < 0 ? self::MINUS : '');

        return $sign . number_format_i18n(abs($value), 1) . '%';
    }

    /**
     * A whole percentage over the listing base, in words.
     */
    public static function ofBase(int $percent): string
    {
        if ($percent === 0) {
            return __('at the base', 'ritmo-revenue');
        }

        return $percent < 0
            /* translators: %s: a percentage such as 20 */
            ? sprintf(__('%s%% below the base', 'ritmo-revenue'), number_format_i18n(abs($percent)))
            /* translators: %s: a percentage such as 15 */
            : sprintf(__('%s%% above the base', 'ritmo-revenue'), number_format_i18n($percent));
    }

    /**
     * A share such as an occupancy: "62%" (no decimals; one decimal below 10%).
     */
    public static function share(?float $percent): string
    {
        if ($percent === null) {
            return '—';
        }

        $decimals = $percent > 0 && $percent < 10 ? 1 : 0;

        return number_format_i18n(Num::roundHalfEven($percent, $decimals), $decimals) . '%';
    }
}
