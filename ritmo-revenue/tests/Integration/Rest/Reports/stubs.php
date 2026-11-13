<?php

/**
 * Stand-ins for the WordPress functions the reports' text and REST answers call, outside
 * WordPress: the English text as it is (a wrong text domain is marked), plural by count, numbers
 * with a dot, dates in UTC. Namespaced, so they only answer the plugin's own unqualified calls.
 */

declare(strict_types=1);

namespace Ritmo\Admin\Reports {
    if (!function_exists(__NAMESPACE__ . '\__')) {
        function __(string $text, string $domain = 'default'): string
        {
            return $domain === 'ritmo-revenue' ? $text : '[wrong text domain] ' . $text;
        }
    }
    if (!function_exists(__NAMESPACE__ . '\_n')) {
        function _n(string $single, string $plural, int $count, string $domain = 'default'): string
        {
            return __($count === 1 ? $single : $plural, $domain);
        }
    }
    if (!function_exists(__NAMESPACE__ . '\wp_date')) {
        function wp_date(string $format, int $timestamp, ?\DateTimeZone $timezone = null): string
        {
            return gmdate($format, $timestamp);
        }
    }
    if (!function_exists(__NAMESPACE__ . '\number_format_i18n')) {
        function number_format_i18n(float $number, int $decimals = 0): string
        {
            return number_format($number, $decimals, '.', ',');
        }
    }
    if (!function_exists(__NAMESPACE__ . '\esc_html')) {
        function esc_html(string $text): string
        {
            return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
        }
    }
}

namespace Ritmo\Rest\Reports {
    if (!function_exists(__NAMESPACE__ . '\__')) {
        function __(string $text, string $domain = 'default'): string
        {
            return $domain === 'ritmo-revenue' ? $text : '[wrong text domain] ' . $text;
        }
    }
}

namespace Ritmo\Admin\Components {
    if (!function_exists(__NAMESPACE__ . '\wp_date')) {
        function wp_date(string $format, int $timestamp, ?\DateTimeZone $timezone = null): string
        {
            return gmdate($format, $timestamp);
        }
    }
    if (!function_exists(__NAMESPACE__ . '\number_format_i18n')) {
        function number_format_i18n(float $number, int $decimals = 0): string
        {
            return number_format($number, $decimals, '.', ',');
        }
    }
    if (!function_exists(__NAMESPACE__ . '\__')) {
        function __(string $text, string $domain = 'default'): string
        {
            return $domain === 'ritmo-revenue' ? $text : '[wrong text domain] ' . $text;
        }
    }
}
