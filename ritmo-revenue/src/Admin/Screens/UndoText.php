<?php

declare(strict_types=1);

namespace Ritmo\Admin\Screens;

/**
 * Why a change was left out of an undo, in one sentence (admin spec 5.11). Reasons the plugin
 * does not know read as one general sentence, never as the code.
 */
final class UndoText
{
    private function __construct()
    {
    }

    public static function reason(string $code): string
    {
        return match ($code) {
            'undo_conflict' => __('The night was changed since by someone else: it is left as it is.', 'ritmo-revenue'),
            'already_undone' => __('This change was already undone.', 'ritmo-revenue'),
            'past_night' => __('The night has passed.', 'ritmo-revenue'),
            'nothing_to_undo' => __('There is nothing left to undo.', 'ritmo-revenue'),
            'unknown_room' => __('The room no longer exists in the plugin.', 'ritmo-revenue'),
            'not_restorable' => __('A fixed price set by someone else cannot be restored by the plugin.', 'ritmo-revenue'),
            'several_changes' => __('Undo the latest change of this night first.', 'ritmo-revenue'),
            'pms_field' => __('Changes in the PMS cannot be undone by the plugin yet: change it in the PMS.', 'ritmo-revenue'),
            'listing_value' => __('Undoing a listing value needs an owner order that releases the listing.', 'ritmo-revenue'),
            default => __('Part of the undo was refused by the safety checks; nothing else was changed.', 'ritmo-revenue'),
        };
    }

    public static function status(string $status): string
    {
        return match ($status) {
            'busy' => __('A run of this property is going on: try the undo again in a minute.', 'ritmo-revenue'),
            'blocked' => __('The undo cannot run now (emergency switch, or the property is in shadow mode).', 'ritmo-revenue'),
            default => '',
        };
    }
}
