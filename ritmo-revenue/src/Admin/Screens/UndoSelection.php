<?php

declare(strict_types=1);

namespace Ritmo\Admin\Screens;

/**
 * The changes a person ticked to undo, kept for their confirmation screen (ten minutes): the undo
 * writes at once, so the change log first shows what goes back and asks again.
 */
final class UndoSelection
{
    private const LIFETIME_SECONDS = 600;

    private const MAX = 500;

    private function __construct()
    {
    }

    /**
     * @param list<int> $changeIds
     */
    public static function keep(array $changeIds): void
    {
        set_transient(self::key(), array_slice(array_values(array_unique($changeIds)), 0, self::MAX), self::LIFETIME_SECONDS);
    }

    /**
     * @return list<int> the ids kept, empty when there are none or their time is over
     */
    public static function ids(): array
    {
        $stored = get_transient(self::key());

        return is_array($stored) ? array_values(array_filter(array_map('intval', $stored), static fn (int $id): bool => $id > 0)) : [];
    }

    public static function forget(): void
    {
        delete_transient(self::key());
    }

    private static function key(): string
    {
        return 'ritmo_undo_selection_' . get_current_user_id();
    }
}
