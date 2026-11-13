<?php
/**
 * Stand-ins for the WordPress functions the screens call, declared in the screens' namespaces so
 * a call resolves to them before the global function (WordPress is not loaded in unit tests).
 * English texts as they are; numbers in en_US.
 */

declare(strict_types=1);

namespace Ritmo\Admin\Components {

    if (!function_exists(__NAMESPACE__ . '\__')) {
        function __(string $text, string $domain = 'default'): string
        {
            return $domain === 'ritmo-revenue' ? $text : '[wrong text domain] ' . $text;
        }
    }
    if (!function_exists(__NAMESPACE__ . '\_n')) {
        function _n(string $single, string $plural, int $number, string $domain = 'default'): string
        {
            return ($domain === 'ritmo-revenue' ? '' : '[wrong text domain] ') . ($number === 1 ? $single : $plural);
        }
    }
    if (!function_exists(__NAMESPACE__ . '\number_format_i18n')) {
        function number_format_i18n(float|int $number, int $decimals = 0): string
        {
            return number_format((float) $number, $decimals, '.', ',');
        }
    }
    if (!function_exists(__NAMESPACE__ . '\wp_date')) {
        function wp_date(string $format, int $timestamp, ?\DateTimeZone $zone = null): string
        {
            return (new \DateTimeImmutable('@' . $timestamp))->setTimezone($zone ?? new \DateTimeZone('UTC'))->format($format);
        }
    }
    if (!function_exists(__NAMESPACE__ . '\get_option')) {
        function get_option(string $name, mixed $default = false): mixed
        {
            return ['date_format' => 'Y-m-d', 'time_format' => 'H:i'][$name] ?? $default;
        }
    }
    if (!function_exists(__NAMESPACE__ . '\human_time_diff')) {
        function human_time_diff(int $from, int $to): string
        {
            return intdiv(abs($to - $from), 60) . ' mins';
        }
    }
    if (!function_exists(__NAMESPACE__ . '\sanitize_text_field')) {
        function sanitize_text_field(string $text): string
        {
            return trim(strip_tags($text));
        }
    }
    if (!function_exists(__NAMESPACE__ . '\sanitize_textarea_field')) {
        function sanitize_textarea_field(string $text): string
        {
            return trim(strip_tags($text));
        }
    }
    if (!function_exists(__NAMESPACE__ . '\wp_unslash')) {
        function wp_unslash(mixed $value): mixed
        {
            return is_string($value) ? stripslashes($value) : (is_array($value) ? array_map(__NAMESPACE__ . '\wp_unslash', $value) : $value);
        }
    }
}

namespace Ritmo\Admin\Screens {

    if (!function_exists(__NAMESPACE__ . '\__')) {
        function __(string $text, string $domain = 'default'): string
        {
            return $domain === 'ritmo-revenue' ? $text : '[wrong text domain] ' . $text;
        }
    }
    if (!function_exists(__NAMESPACE__ . '\_n')) {
        function _n(string $single, string $plural, int $number, string $domain = 'default'): string
        {
            return ($domain === 'ritmo-revenue' ? '' : '[wrong text domain] ') . ($number === 1 ? $single : $plural);
        }
    }
    if (!function_exists(__NAMESPACE__ . '\number_format_i18n')) {
        function number_format_i18n(float|int $number, int $decimals = 0): string
        {
            return number_format((float) $number, $decimals, '.', ',');
        }
    }
    if (!function_exists(__NAMESPACE__ . '\wp_date')) {
        function wp_date(string $format, int $timestamp, ?\DateTimeZone $zone = null): string
        {
            return (new \DateTimeImmutable('@' . $timestamp))->setTimezone($zone ?? new \DateTimeZone('UTC'))->format($format);
        }
    }
    if (!function_exists(__NAMESPACE__ . '\get_option')) {
        function get_option(string $name, mixed $default = false): mixed
        {
            return ['date_format' => 'Y-m-d', 'time_format' => 'H:i'][$name] ?? $default;
        }
    }
    if (!function_exists(__NAMESPACE__ . '\human_time_diff')) {
        function human_time_diff(int $from, int $to): string
        {
            return intdiv(abs($to - $from), 60) . ' mins';
        }
    }
    if (!function_exists(__NAMESPACE__ . '\sanitize_text_field')) {
        function sanitize_text_field(string $text): string
        {
            return trim(strip_tags($text));
        }
    }
    if (!function_exists(__NAMESPACE__ . '\sanitize_textarea_field')) {
        function sanitize_textarea_field(string $text): string
        {
            return trim(strip_tags($text));
        }
    }
    if (!function_exists(__NAMESPACE__ . '\wp_unslash')) {
        function wp_unslash(mixed $value): mixed
        {
            return is_string($value) ? stripslashes($value) : (is_array($value) ? array_map(__NAMESPACE__ . '\wp_unslash', $value) : $value);
        }
    }
}

namespace Ritmo\Admin {

    if (!function_exists(__NAMESPACE__ . '\__')) {
        function __(string $text, string $domain = 'default'): string
        {
            return $domain === 'ritmo-revenue' ? $text : '[wrong text domain] ' . $text;
        }
    }
    if (!function_exists(__NAMESPACE__ . '\_n')) {
        function _n(string $single, string $plural, int $number, string $domain = 'default'): string
        {
            return ($domain === 'ritmo-revenue' ? '' : '[wrong text domain] ') . ($number === 1 ? $single : $plural);
        }
    }
    if (!function_exists(__NAMESPACE__ . '\number_format_i18n')) {
        function number_format_i18n(float|int $number, int $decimals = 0): string
        {
            return number_format((float) $number, $decimals, '.', ',');
        }
    }
    if (!function_exists(__NAMESPACE__ . '\wp_date')) {
        function wp_date(string $format, int $timestamp, ?\DateTimeZone $zone = null): string
        {
            return (new \DateTimeImmutable('@' . $timestamp))->setTimezone($zone ?? new \DateTimeZone('UTC'))->format($format);
        }
    }
    if (!function_exists(__NAMESPACE__ . '\get_option')) {
        function get_option(string $name, mixed $default = false): mixed
        {
            return ['date_format' => 'Y-m-d', 'time_format' => 'H:i'][$name] ?? $default;
        }
    }
    if (!function_exists(__NAMESPACE__ . '\human_time_diff')) {
        function human_time_diff(int $from, int $to): string
        {
            return intdiv(abs($to - $from), 60) . ' mins';
        }
    }
    if (!function_exists(__NAMESPACE__ . '\sanitize_text_field')) {
        function sanitize_text_field(string $text): string
        {
            return trim(strip_tags($text));
        }
    }
    if (!function_exists(__NAMESPACE__ . '\sanitize_textarea_field')) {
        function sanitize_textarea_field(string $text): string
        {
            return trim(strip_tags($text));
        }
    }
    if (!function_exists(__NAMESPACE__ . '\wp_unslash')) {
        function wp_unslash(mixed $value): mixed
        {
            return is_string($value) ? stripslashes($value) : (is_array($value) ? array_map(__NAMESPACE__ . '\wp_unslash', $value) : $value);
        }
    }
}

namespace Ritmo\Rest\Screens {

    if (!function_exists(__NAMESPACE__ . '\__')) {
        function __(string $text, string $domain = 'default'): string
        {
            return $domain === 'ritmo-revenue' ? $text : '[wrong text domain] ' . $text;
        }
    }
    if (!function_exists(__NAMESPACE__ . '\_n')) {
        function _n(string $single, string $plural, int $number, string $domain = 'default'): string
        {
            return ($domain === 'ritmo-revenue' ? '' : '[wrong text domain] ') . ($number === 1 ? $single : $plural);
        }
    }
    if (!function_exists(__NAMESPACE__ . '\number_format_i18n')) {
        function number_format_i18n(float|int $number, int $decimals = 0): string
        {
            return number_format((float) $number, $decimals, '.', ',');
        }
    }
    if (!function_exists(__NAMESPACE__ . '\wp_date')) {
        function wp_date(string $format, int $timestamp, ?\DateTimeZone $zone = null): string
        {
            return (new \DateTimeImmutable('@' . $timestamp))->setTimezone($zone ?? new \DateTimeZone('UTC'))->format($format);
        }
    }
    if (!function_exists(__NAMESPACE__ . '\get_option')) {
        function get_option(string $name, mixed $default = false): mixed
        {
            return ['date_format' => 'Y-m-d', 'time_format' => 'H:i'][$name] ?? $default;
        }
    }
    if (!function_exists(__NAMESPACE__ . '\human_time_diff')) {
        function human_time_diff(int $from, int $to): string
        {
            return intdiv(abs($to - $from), 60) . ' mins';
        }
    }
    if (!function_exists(__NAMESPACE__ . '\sanitize_text_field')) {
        function sanitize_text_field(string $text): string
        {
            return trim(strip_tags($text));
        }
    }
    if (!function_exists(__NAMESPACE__ . '\sanitize_textarea_field')) {
        function sanitize_textarea_field(string $text): string
        {
            return trim(strip_tags($text));
        }
    }
    if (!function_exists(__NAMESPACE__ . '\wp_unslash')) {
        function wp_unslash(mixed $value): mixed
        {
            return is_string($value) ? stripslashes($value) : (is_array($value) ? array_map(__NAMESPACE__ . '\wp_unslash', $value) : $value);
        }
    }
}
