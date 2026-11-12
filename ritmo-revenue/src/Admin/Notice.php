<?php

declare(strict_types=1);

namespace Ritmo\Admin;

/**
 * The message shown once on the page an admin action redirects to.
 */
final class Notice
{
    public const SUCCESS = 'success';

    public const ERROR = 'error';

    public const WARNING = 'warning';

    private const LIFETIME_SECONDS = 120;

    private function __construct()
    {
    }

    /**
     * @param list<string> $details lines shown under the message (validation errors, counts)
     */
    public static function flash(string $type, string $message, array $details = []): void
    {
        set_transient(self::key(), ['type' => $type, 'message' => $message, 'details' => array_slice($details, 0, 50)], self::LIFETIME_SECONDS);
    }

    /**
     * The pending notice of the current user, removed as it is read.
     *
     * @return array{type: string, message: string, details: list<string>}|null
     */
    public static function take(): ?array
    {
        $notice = get_transient(self::key());
        if ($notice === false) {
            return null;
        }
        delete_transient(self::key());

        return is_array($notice) && isset($notice['type'], $notice['message']) ? [
            'type' => (string) $notice['type'],
            'message' => (string) $notice['message'],
            'details' => array_map('strval', (array) ($notice['details'] ?? [])),
        ] : null;
    }

    private static function key(): string
    {
        return 'ritmo_notice_' . get_current_user_id();
    }
}
