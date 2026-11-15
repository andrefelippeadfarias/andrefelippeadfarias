<?php

declare(strict_types=1);

namespace Ritmo\Admin\Editors;

use Ritmo\Application\Rules\PrivacyCheck;

/**
 * What a person typed in an order form that was refused, kept for their next screen (ten
 * minutes) so the form comes back filled in and only the marked values need a fix. A note that
 * looks like guest data is never kept.
 */
final class OrderFormDraft
{
    private const LIFETIME_SECONDS = 600;

    private const NOTE_MAX = 500;

    private function __construct()
    {
    }

    /**
     * @param array<string, mixed> $fields the posted order fields (rooms, from, to, nights, floors, percentile, releases, note)
     */
    public static function keep(array $fields): void
    {
        $note = is_string($fields['note'] ?? null) ? $fields['note'] : '';
        set_transient(self::key(), [
            'rooms' => self::strings($fields['rooms'] ?? []),
            'from' => self::date($fields['from'] ?? ''),
            'to' => self::date($fields['to'] ?? ''),
            'nights' => array_values(array_filter(self::strings($fields['nights'] ?? []), static fn (string $n): bool => self::date($n) !== '')),
            'floors' => array_filter(array_map(static fn (mixed $v): string => is_scalar($v) && preg_match('/^\d{1,7}([.,]\d{1,2})?$/', trim((string) $v)) === 1 ? trim((string) $v) : '', is_array($fields['floors'] ?? null) ? $fields['floors'] : []), static fn (string $v): bool => $v !== ''),
            'percentile' => is_numeric($fields['percentile'] ?? null) ? (int) $fields['percentile'] : null,
            'releases' => self::strings($fields['releases'] ?? []),
            'note' => PrivacyCheck::problem($note, self::NOTE_MAX) === null ? $note : '',
        ], self::LIFETIME_SECONDS);
    }

    /**
     * The values kept, once (they are forgotten as they are read); null when there are none.
     *
     * @return array<string, mixed>|null
     */
    public static function take(): ?array
    {
        $stored = get_transient(self::key());
        delete_transient(self::key());

        return is_array($stored) ? $stored : null;
    }

    /**
     * @return list<string>
     */
    private static function strings(mixed $values): array
    {
        return array_values(array_filter(array_map(static fn (mixed $v): string => is_string($v) && preg_match('/^[A-Za-z0-9_*-]{1,64}$|^\d{4}-\d{2}-\d{2}$/', $v) === 1 ? $v : '', is_array($values) ? $values : []), static fn (string $v): bool => $v !== ''));
    }

    private static function date(mixed $value): string
    {
        return is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1 ? $value : '';
    }

    private static function key(): string
    {
        return 'ritmo_order_form_' . get_current_user_id();
    }
}
