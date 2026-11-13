<?php

/**
 * Stand-ins for the WordPress functions the rules and market REST answers call, outside
 * WordPress: the English text as it is (a wrong text domain is marked), plural by count, dates
 * as YYYY-MM-DD, sizes in KB. Namespaced, so they only answer the plugin's own unqualified calls.
 */

declare(strict_types=1);

namespace Ritmo\Rest\Market {
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
}

namespace Ritmo\Rest\Editors {
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
}

namespace Ritmo\Admin\Market {
    if (!function_exists(__NAMESPACE__ . '\get_option')) {
        function get_option(string $name): string
        {
            return $name === 'date_format' ? 'Y-m-d' : '';
        }
    }
    if (!function_exists(__NAMESPACE__ . '\wp_date')) {
        function wp_date(string $format, int $timestamp, ?\DateTimeZone $zone = null): string
        {
            return (new \DateTimeImmutable('@' . $timestamp))->setTimezone($zone ?? new \DateTimeZone('UTC'))->format($format);
        }
    }
    if (!function_exists(__NAMESPACE__ . '\size_format')) {
        function size_format(int $bytes): string
        {
            return intdiv($bytes, 1024) . ' KB';
        }
    }
}
