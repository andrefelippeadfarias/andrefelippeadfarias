<?php

declare(strict_types=1);

namespace Ritmo\Infrastructure\Schedule;

use Ritmo\Contracts\KeyValueStore;

/**
 * The alert recipients: the users who may approve plans and did not opt out (user meta
 * "ritmo_alert_email" = "no"), plus the extra addresses of the site setting
 * "notifications.extra_addresses" (a list; never part of a preset).
 */
final class WpRecipientDirectory implements RecipientDirectory
{
    public const CAPABILITY = 'ritmo_approve';

    public const OPT_OUT_META = 'ritmo_alert_email';

    public const EXTRA_KEY = 'notifications.extra_addresses';

    public function __construct(private readonly KeyValueStore $settings)
    {
    }

    public function addresses(): array
    {
        $addresses = [];
        foreach (get_users(['capability' => self::CAPABILITY, 'fields' => ['ID', 'user_email']]) as $user) {
            if (get_user_meta((int) $user->ID, self::OPT_OUT_META, true) !== 'no') {
                $addresses[] = (string) $user->user_email;
            }
        }
        $extra = $this->settings->get(self::EXTRA_KEY, []);
        foreach (is_array($extra) ? $extra : [] as $address) {
            $addresses[] = (string) $address;
        }
        $valid = array_filter($addresses, static fn (string $a): bool => filter_var($a, FILTER_VALIDATE_EMAIL) !== false);

        return array_values(array_unique(array_map('strtolower', $valid)));
    }
}
