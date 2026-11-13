<?php

declare(strict_types=1);

namespace Ritmo\Admin\Editors;

/**
 * The errors of the last posted rules form, by path, kept for the next screen of the same person
 * (two minutes), so each field shows its own error next to it after the redirect.
 */
final class FormErrors
{
    private const LIFETIME_SECONDS = 120;

    private function __construct()
    {
    }

    /**
     * @param array<string, string> $byPath path => sentence
     */
    public static function flash(array $byPath): void
    {
        set_transient(self::key(), array_slice($byPath, 0, 200, true), self::LIFETIME_SECONDS);
    }

    /**
     * @return array<string, string>
     */
    public static function take(): array
    {
        $stored = get_transient(self::key());
        delete_transient(self::key());

        return is_array($stored) ? array_filter(array_map(static fn (mixed $v): string => is_string($v) ? $v : '', $stored)) : [];
    }

    private static function key(): string
    {
        return 'ritmo_rules_errors_' . get_current_user_id();
    }
}
