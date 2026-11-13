<?php

declare(strict_types=1);

namespace Ritmo\Admin\Screens;

use Ritmo\Application\Grid\Approvals\EntryRow;
use Ritmo\Application\Grid\Approvals\EntryWarnings;

/**
 * The warnings of the approval screen (admin spec 5.4): a short badge text and the full sentence.
 */
final class WarningText
{
    private function __construct()
    {
    }

    public static function short(string $code, EntryRow $entry): string
    {
        return match ($code) {
            EntryWarnings::TONIGHT => __('Tonight', 'ritmo-revenue'),
            EntryWarnings::TOMORROW => __('Tomorrow', 'ritmo-revenue'),
            /* translators: %s: name of the holiday */
            EntryWarnings::HOLIDAY => sprintf(__('Holiday: %s', 'ritmo-revenue'), self::holiday($entry)),
            EntryWarnings::GROUP => __('Goes together', 'ritmo-revenue'),
            EntryWarnings::RELATION => __('Linked rooms', 'ritmo-revenue'),
            EntryWarnings::HELD => __('Held', 'ritmo-revenue'),
            EntryWarnings::ORDER => __('Owner order', 'ritmo-revenue'),
            default => __('Note', 'ritmo-revenue'),
        };
    }

    public static function long(string $code, EntryRow $entry): string
    {
        $sync = __('After writing, press Sync Now in the pricing system for this room so the channel gets it today.', 'ritmo-revenue');

        return match ($code) {
            EntryWarnings::TONIGHT => __('Tonight: the price can only stay or go down.', 'ritmo-revenue') . ' ' . $sync,
            EntryWarnings::TOMORROW => __('Tomorrow.', 'ritmo-revenue') . ' ' . $sync,
            EntryWarnings::HOLIDAY => self::short($code, $entry),
            EntryWarnings::GROUP => __('A deletion and the rewrite of this night go together: approving one approves both.', 'ritmo-revenue'),
            EntryWarnings::RELATION => __('This room is linked to another room of the plan on this night by one of your relations.', 'ritmo-revenue'),
            EntryWarnings::HELD => __('Held by the cancellation wave until a person releases it (Alerts).', 'ritmo-revenue'),
            EntryWarnings::ORDER => __('Allowed by an owner order.', 'ritmo-revenue'),
            default => '',
        };
    }

    private static function holiday(EntryRow $entry): string
    {
        return str_starts_with($entry->special, EntryWarnings::HOLIDAY . ':') ? substr($entry->special, strlen(EntryWarnings::HOLIDAY) + 1) : __('holiday', 'ritmo-revenue');
    }
}
