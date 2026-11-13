<?php

declare(strict_types=1);

namespace Ritmo\Admin\Market;

use Ritmo\Application\Market\PreviewStore;

/**
 * The preview of a price file kept in a WordPress transient ("ritmo_csv_<user>_<16 hex>") for
 * 30 minutes: only the parsed valid rows and the problems, never the file. One preview per
 * person: a new one replaces the previous one.
 */
final class TransientPreviewStore implements PreviewStore
{
    private const LAST = 'ritmo_csv_last_';

    public function put(string $token, array $payload, int $ttlSeconds): void
    {
        $user = get_current_user_id();
        $previous = get_transient(self::LAST . $user);
        if (is_string($previous) && $previous !== $token) {
            delete_transient($previous);
        }
        set_transient($token, $payload, $ttlSeconds);
        set_transient(self::LAST . $user, $token, $ttlSeconds);
    }

    public function get(string $token): ?array
    {
        $payload = get_transient($token);

        return is_array($payload) ? $payload : null;
    }

    public function delete(string $token): void
    {
        delete_transient($token);
    }
}
