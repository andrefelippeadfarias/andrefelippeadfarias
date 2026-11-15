<?php

/**
 * Stand-ins for the WordPress functions the assistant's text class calls, outside WordPress: the
 * English text as it is (a wrong text domain is marked), numbers with a dot. Namespaced, so they
 * only answer the plugin's own unqualified calls.
 */

declare(strict_types=1);

namespace Ritmo\Admin\Ai {
    if (!function_exists(__NAMESPACE__ . '\__')) {
        function __(string $text, string $domain = 'default'): string
        {
            return $domain === 'ritmo-revenue' ? $text : '[wrong text domain] ' . $text;
        }
    }
    if (!function_exists(__NAMESPACE__ . '\number_format_i18n')) {
        function number_format_i18n(float $number, int $decimals = 0): string
        {
            return number_format($number, $decimals, '.', ',');
        }
    }
}
