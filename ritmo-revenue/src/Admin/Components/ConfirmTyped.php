<?php

declare(strict_types=1);

namespace Ritmo\Admin\Components;

/**
 * The typed confirmation of risky actions (admin spec 4.6, decision D10): what to type is a
 * property id, a value or a provider name, never a translated word; the server compares after
 * trim() and a mismatch refuses the whole action.
 */
final class ConfirmTyped
{
    private function __construct()
    {
    }

    /**
     * @throws UserFacing when the typed text is not the expected one
     */
    public static function check(string $expected, string $typed): void
    {
        if ($expected === '' || trim($typed) !== $expected) {
            /* translators: %s: the exact text to type, such as a property id or "Beds24" */
            throw new UserFacing(sprintf(__('The confirmation does not match: type %s to confirm.', 'ritmo-revenue'), $expected));
        }
    }
}
