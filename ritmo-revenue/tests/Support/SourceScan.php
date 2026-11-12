<?php

declare(strict_types=1);

namespace Ritmo\Tests\Support;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Static checks over PHP source, used by the architecture tests. It reads tokens, so words in
 * comments and strings never count.
 */
final class SourceScan
{
    /** WordPress functions and constants, matched exactly (functions case-insensitively). */
    private const WORDPRESS_NAMES = [
        'add_action', 'add_filter', 'do_action', 'apply_filters', 'remove_action', 'remove_filter', 'has_action', 'has_filter',
        'get_option', 'update_option', 'add_option', 'delete_option', 'get_site_option', 'update_site_option',
        'get_transient', 'set_transient', 'delete_transient', 'current_user_can', 'get_current_user_id', 'is_wp_error',
        'is_admin', 'absint', '__', '_e', '_x', '_n', '_ex', '_nx', '_n_noop', 'plugin_dir_path', 'plugin_dir_url',
        'plugins_url', 'plugin_basename', 'admin_url', 'home_url', 'site_url', 'rest_url', 'get_site_url', 'get_bloginfo',
        'register_rest_route', 'register_activation_hook', 'register_deactivation_hook', 'check_ajax_referer',
        'check_admin_referer', 'dbdelta', 'maybe_serialize', 'maybe_unserialize', 'trailingslashit', 'untrailingslashit',
        'load_plugin_textdomain', 'wpdb', 'abspath', 'wpinc', 'array_a', 'day_in_seconds', 'hour_in_seconds', 'minute_in_seconds',
        'actionscheduler',
    ];

    /** Any identifier starting with one of these belongs to WordPress or to a plugin API. */
    private const WORDPRESS_PREFIXES = ['wp_', 'esc_', 'sanitize_', 'as_', 'wc_'];

    /** Functions that read the system clock or depend on the default timezone. */
    private const CLOCK_FUNCTIONS = [
        'time', 'date', 'gmdate', 'idate', 'getdate', 'localtime', 'strtotime', 'mktime', 'gmmktime', 'microtime', 'hrtime',
        'date_create', 'date_create_immutable', 'strftime', 'gmstrftime', 'date_default_timezone_get', 'date_default_timezone_set',
    ];

    private const ROUNDING_FUNCTIONS = ['round', 'ceil', 'floor', 'number_format'];

    /**
     * Every .php file under a directory, sorted.
     *
     * @return list<string>
     */
    public static function phpFiles(string $directory): array
    {
        if (!is_dir($directory)) {
            return [];
        }
        $files = [];
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }
        sort($files);

        return $files;
    }

    /**
     * WordPress functions, classes, constants and globals the code mentions.
     *
     * @return list<string>
     */
    public static function wordPressMentions(string $code): array
    {
        $found = [];
        foreach (self::significantTokens($code) as $token) {
            if ($token[0] === T_VARIABLE && $token[1] === '$wpdb') {
                $found[] = '$wpdb';
            }
            if (!in_array($token[0], [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)) {
                continue;
            }
            $name = strtolower(self::lastSegment($token[1]));
            if (in_array($name, self::WORDPRESS_NAMES, true) || self::startsWithAny($name, self::WORDPRESS_PREFIXES)) {
                $found[] = $token[1];
            }
        }

        return array_values(array_unique($found));
    }

    /**
     * Reads of the system clock: calls to time functions and date objects created for "now".
     *
     * @return list<string>
     */
    public static function clockReads(string $code): array
    {
        $tokens = self::significantTokens($code);
        $found = self::functionCalls($tokens, self::CLOCK_FUNCTIONS);
        foreach ($tokens as $index => $token) {
            $class = strtolower(self::lastSegment((string) ($tokens[$index + 1][1] ?? '')));
            if ($token[0] !== T_NEW || !in_array($class, ['datetime', 'datetimeimmutable'], true)) {
                continue;
            }
            $open = $tokens[$index + 2][1] ?? '';
            $first = $tokens[$index + 3] ?? [0, ''];
            $literal = $first[0] === T_CONSTANT_ENCAPSED_STRING ? strtolower(trim($first[1], '\'"')) : null;
            if ($open !== '(' || $first[1] === ')' || $literal === 'now' || $literal === '') {
                $found[] = 'new ' . $tokens[$index + 1][1] . '(now)';
            }
        }

        return array_values(array_unique($found));
    }

    /**
     * Calls to PHP's own rounding functions.
     *
     * @return list<string>
     */
    public static function roundingCalls(string $code): array
    {
        return self::functionCalls(self::significantTokens($code), self::ROUNDING_FUNCTIONS);
    }

    /**
     * True when the first statement of the file is declare(strict_types=1).
     */
    public static function declaresStrictTypes(string $code): bool
    {
        $tokens = self::significantTokens($code);
        $text = '';
        foreach (array_slice($tokens, 1, 7) as $token) {
            $text .= $token[1];
        }

        return ($tokens[0][0] ?? null) === T_OPEN_TAG && str_starts_with($text, 'declare(strict_types=1);');
    }

    /**
     * The namespace and the classes, interfaces, traits and enums a file declares.
     *
     * @return array{namespace: string, types: list<string>}
     */
    public static function declarations(string $code): array
    {
        $tokens = self::significantTokens($code);
        $namespace = '';
        $types = [];
        foreach ($tokens as $index => $token) {
            $next = $tokens[$index + 1] ?? [0, ''];
            $previous = $tokens[$index - 1] ?? [0, ''];
            if ($token[0] === T_NAMESPACE && in_array($next[0], [T_STRING, T_NAME_QUALIFIED], true)) {
                $namespace = $next[1];
            }
            $declares = in_array($token[0], [T_CLASS, T_INTERFACE, T_TRAIT, T_ENUM], true);
            if ($declares && $next[0] === T_STRING && $previous[0] !== T_DOUBLE_COLON && $previous[0] !== T_NEW) {
                $types[] = $next[1];
            }
        }

        return ['namespace' => $namespace, 'types' => $types];
    }

    /**
     * Tokens without whitespace, comments and inline HTML, each as [id, text]; single-character
     * tokens get the id 0.
     *
     * @return list<array{int, string}>
     */
    private static function significantTokens(string $code): array
    {
        $tokens = [];
        foreach (token_get_all($code) as $token) {
            if (is_string($token)) {
                $tokens[] = [0, $token];
            } elseif (!in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT, T_INLINE_HTML], true)) {
                $tokens[] = [$token[0], $token[1]];
            }
        }

        return $tokens;
    }

    /**
     * Plain function calls (not methods, not declarations) to any of the given functions.
     *
     * @param list<array{int, string}> $tokens
     * @param list<string>             $functions lowercase names
     *
     * @return list<string>
     */
    private static function functionCalls(array $tokens, array $functions): array
    {
        $notACall = [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION, T_NEW, T_CONST];
        $found = [];
        foreach ($tokens as $index => $token) {
            $isName = $token[0] === T_STRING || ($token[0] === T_NAME_FULLY_QUALIFIED && substr_count($token[1], '\\') === 1);
            if (!$isName || ($tokens[$index + 1][1] ?? '') !== '(' || in_array($tokens[$index - 1][0] ?? 0, $notACall, true)) {
                continue;
            }
            $name = strtolower(self::lastSegment($token[1]));
            if (in_array($name, $functions, true)) {
                $found[] = $name . '()';
            }
        }

        return array_values(array_unique($found));
    }

    private static function lastSegment(string $name): string
    {
        $position = strrpos($name, '\\');

        return $position === false ? $name : substr($name, $position + 1);
    }

    /**
     * @param list<string> $prefixes
     */
    private static function startsWithAny(string $name, array $prefixes): bool
    {
        foreach ($prefixes as $prefix) {
            if (str_starts_with($name, $prefix)) {
                return true;
            }
        }

        return false;
    }
}
