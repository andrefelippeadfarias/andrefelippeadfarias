<?php
/**
 * Stand-ins for the few WordPress functions and the class the update hooks use, declared in the
 * hooks' namespace so a call resolves to them first (WordPress is not loaded in this suite). The
 * filters registered are kept in UpdateHooksStubs::$filters.
 */

declare(strict_types=1);

namespace {
    if (!class_exists('WP_Error')) {
        class WP_Error
        {
            public function __construct(public string $code = '', public string $message = '')
            {
            }

            public function get_error_code(): string
            {
                return $this->code;
            }

            public function get_error_message(): string
            {
                return $this->message;
            }
        }
    }
}

namespace Ritmo\Tests\Integration\Update {
    final class UpdateHooksStubs
    {
        /** @var array<string, array{callable, int, int}> */
        public static array $filters = [];

        /** @var list<string> */
        public static array $tempFiles = [];
    }
}

namespace Ritmo\Infrastructure\Update {
    use Ritmo\Tests\Integration\Update\UpdateHooksStubs;

    if (!function_exists(__NAMESPACE__ . '\add_filter')) {
        function add_filter(string $hook, callable $callback, int $priority = 10, int $arguments = 1): bool
        {
            UpdateHooksStubs::$filters[$hook] = [$callback, $priority, $arguments];

            return true;
        }
    }
    if (!function_exists(__NAMESPACE__ . '\wp_tempnam')) {
        function wp_tempnam(string $filename = ''): string
        {
            $file = (string) tempnam(sys_get_temp_dir(), 'ritmo-update-');
            UpdateHooksStubs::$tempFiles[] = $file;

            return $file;
        }
    }
    if (!function_exists(__NAMESPACE__ . '\esc_html')) {
        function esc_html(string $text): string
        {
            return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
        }
    }
}
