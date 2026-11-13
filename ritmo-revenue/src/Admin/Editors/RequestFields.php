<?php

declare(strict_types=1);

namespace Ritmo\Admin\Editors;

/**
 * The nested fields of a posted editor form ("f[floors][owner][0][amount]"), unslashed and
 * sanitised as text (tags stripped, line breaks kept for one-value-per-line lists); a key that is
 * not a plain name is dropped. Read only by
 * the form handlers, after the capability and the nonce were checked.
 */
final class RequestFields
{
    private const MAX_DEPTH = 12;

    private function __construct()
    {
    }

    /**
     * @return array<int|string, mixed> an empty array when the field is absent or not a group
     */
    public static function posted(string $name): array
    {
        $value = $_POST[$name] ?? null; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- checked by the action dispatcher

        return is_array($value) ? self::clean(wp_unslash($value), 0) : [];
    }

    /**
     * @param array<int|string, mixed> $value
     *
     * @return array<int|string, mixed>
     */
    private static function clean(array $value, int $depth): array
    {
        $clean = [];
        foreach ($value as $key => $item) {
            if (!is_int($key) && preg_match('/^[A-Za-z0-9_.:-]{1,64}$/', $key) !== 1) {
                continue;
            }
            if (is_array($item) && $depth < self::MAX_DEPTH) {
                $clean[$key] = self::clean($item, $depth + 1);
            } elseif (is_string($item)) {
                $clean[$key] = sanitize_textarea_field($item);
            }
        }

        return $clean;
    }
}
