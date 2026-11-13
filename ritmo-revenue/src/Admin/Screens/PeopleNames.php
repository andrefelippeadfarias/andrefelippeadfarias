<?php

declare(strict_types=1);

namespace Ritmo\Admin\Screens;

use Ritmo\Admin\Components\PrivacyGuard;

/**
 * The display name of a WordPress user who approved or asked for something; never the e-mail.
 * A user deleted since reads "a former user".
 */
final class PeopleNames
{
    /** @var array<int, string> */
    private static array $names = [];

    private function __construct()
    {
    }

    public static function name(int $userId): string
    {
        if (!isset(self::$names[$userId])) {
            $user = get_userdata($userId);
            $name = $user === false ? '' : (string) $user->display_name;
            self::$names[$userId] = match (true) {
                $user === false => __('a former user', 'ritmo-revenue'),
                /* translators: %d: user id */
                $name === '' || PrivacyGuard::looksPrivate($name) => sprintf(__('user %d', 'ritmo-revenue'), $userId),
                default => $name,
            };
        }

        return self::$names[$userId];
    }
}
