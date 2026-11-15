<?php

declare(strict_types=1);

namespace Ritmo\Admin\Screens;

/**
 * Why a room of the setup could not be saved, in the reader's language: the refusals of the room
 * step (SetupWizard::saveRooms, SetupService::roomType, RoomType) are English sentences of the
 * application; each known one is matched here and said again with its facts. An unknown one is
 * shown as it is.
 */
final class RoomErrorText
{
    private function __construct()
    {
    }

    public static function of(string $message): string
    {
        $matches = [];
        if (preg_match('/^The PMS room or the pricing listing is already mapped to the room "([^"]{0,64})"\.$/', $message, $matches) === 1) {
            /* translators: %s: the code of the room that already has that PMS room or listing */
            return sprintf(__('The PMS room or the pricing listing is already in room %s (of this or another property). Tick "Do not manage this room" for a room that another property already manages.', 'ritmo-revenue'), $matches[1]);
        }
        if (preg_match('/^Invalid room type code "([^"]{0,64})"\.$/', $message, $matches) === 1) {
            /* translators: %s: the code as typed */
            return sprintf(__('The room code "%s" is not valid: use letters, digits, "-" and "_".', 'ritmo-revenue'), $matches[1]);
        }
        if (preg_match('/^Room type "(min|base|max|reference_min)" must be greater than zero\.$/', $message, $matches) === 1) {
            return match ($matches[1]) {
                'min' => __('The minimum price must be greater than zero.', 'ritmo-revenue'),
                'max' => __('The maximum price must be greater than zero.', 'ritmo-revenue'),
                'reference_min' => __('The reference minimum must be greater than zero.', 'ritmo-revenue'),
                default => __('The base price must be greater than zero.', 'ritmo-revenue'),
            };
        }

        return match ($message) {
            'Add at least one room.' => __('Add at least one room.', 'ritmo-revenue'),
            'A room type needs a name.' => __('Give the room a name.', 'ritmo-revenue'),
            'A room type needs at least one unit.' => __('A room has at least one unit.', 'ritmo-revenue'),
            'Room type "max" must not be lower than "min".' => __('The maximum price must not be lower than the minimum.', 'ritmo-revenue'),
            'Only a person allowed to change safety limits can change the minimum, base or maximum of an existing room.' => __('Only a person allowed to change the safety limits can change the minimum, base or maximum of an existing room.', 'ritmo-revenue'),
            'Only a person allowed to change safety limits can add a room to a property that is not in shadow mode.' => __('Only a person allowed to change the safety limits can add a room to a property that is not in shadow mode.', 'ritmo-revenue'),
            default => $message,
        };
    }
}
