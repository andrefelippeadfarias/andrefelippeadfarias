<?php

declare(strict_types=1);

namespace Ritmo\Admin\Editors;

use Ritmo\Application\Rules\RuleDraft;
use Ritmo\Application\Rules\RuleDraftStorage;

/**
 * Each person's rules draft in their user meta "ritmo_rules_draft" (spec 05, 5.5.1): it survives
 * a reload and a new login, and only that person sees it.
 */
final class UserMetaDraftStorage implements RuleDraftStorage
{
    public const META = 'ritmo_rules_draft';

    public function get(int $userId): ?RuleDraft
    {
        $stored = $userId > 0 ? get_user_meta($userId, self::META, true) : null;

        return is_array($stored) ? RuleDraft::fromArray($stored) : null;
    }

    public function put(int $userId, RuleDraft $draft): void
    {
        if ($userId > 0) {
            update_user_meta($userId, self::META, $draft->toArray());
        }
    }

    public function clear(int $userId): void
    {
        if ($userId > 0) {
            delete_user_meta($userId, self::META);
        }
    }
}
