<?php

declare(strict_types=1);

namespace Ritmo\Admin\Components;

use Ritmo\Admin\Screens\RoomErrorText;

/**
 * The refusals of the setup (SetupService, SetupWizard, SampleCsv) in the reader's language: they
 * are English sentences of the application; each known one is matched here and said again with
 * its facts (the room step's own ones by RoomErrorText). of() is null for an unknown one.
 */
final class OwnMessageText
{
    private function __construct()
    {
    }

    public static function of(string $message): ?string
    {
        $matches = [];
        if (preg_match('/^"([a-z_]{1,40})" must be a whole number\.$/', $message, $matches) === 1) {
            /* translators: %s: the name of the field as sent by the form */
            return sprintf(__('"%s" must be a whole number.', 'ritmo-revenue'), $matches[1]);
        }
        if (preg_match('/^"([a-z_]{1,40})" must be an amount such as 480 or 480\.50\.$/', $message, $matches) === 1) {
            /* translators: %s: the name of the field as sent by the form */
            return sprintf(__('"%s" must be an amount such as 480 or 480.50.', 'ritmo-revenue'), $matches[1]);
        }
        if (preg_match('/^Paste at most (\d{1,6}) lines at a time\.$/', $message, $matches) === 1) {
            /* translators: %d: the most lines that can be pasted */
            return sprintf(__('Paste at most %d lines at a time.', 'ritmo-revenue'), (int) $matches[1]);
        }
        if (preg_match('/^Line (\d{1,6}): (.+)$/', $message, $matches) === 1) {
            $line = (int) $matches[1];

            return match ($matches[2]) {
                /* translators: %d: the line number */
                'write exactly a date and a price.' => sprintf(__('Line %d: write exactly a date and a price.', 'ritmo-revenue'), $line),
                /* translators: %d: the line number */
                'the date must be written as YYYY-MM-DD.' => sprintf(__('Line %d: the date must be written as YYYY-MM-DD.', 'ritmo-revenue'), $line),
                /* translators: %d: the line number */
                'the price must be a positive number such as 480 or 480.50.' => sprintf(__('Line %d: the price must be a positive number such as 480 or 480.50.', 'ritmo-revenue'), $line),
                default => null,
            };
        }
        $known = match ($message) {
            'The property id is a short code of letters, digits, "-" and "_" (up to 32).' => __('The property code has up to 32 letters, digits, "-" and "_".', 'ritmo-revenue'),
            'A property with this id already exists.' => __('A property with this code already exists. Choose another name (or another code), or open the property that is already there.', 'ritmo-revenue'),
            'The property needs a name of up to 100 characters.' => __('Give the property a name of up to 100 characters.', 'ritmo-revenue'),
            'Choose the timezone of the property from the list.' => __('Choose the timezone of the property from the list.', 'ritmo-revenue'),
            'The currency is a three-letter code such as EUR, USD or BRL.' => __('The currency is a three-letter code such as BRL, USD or EUR.', 'ritmo-revenue'),
            'The tier is a lowercase name such as "standard" or "premium".' => __('The tier is a lowercase name such as "standard" or "premium".', 'ritmo-revenue'),
            'Choose an existing property for the room type.' => __('Choose an existing property for the room.', 'ritmo-revenue'),
            'Choose an existing property for the prices.' => __('Choose an existing property for the prices.', 'ritmo-revenue'),
            'Only a person allowed to change safety limits can import a preset.' => __('Only a person allowed to change the safety limits can import a preset.', 'ritmo-revenue'),
            'Only a person allowed to change safety limits can confirm them.' => __('Only a person allowed to change the safety limits can confirm them.', 'ritmo-revenue'),
            'No price was found: write one "date,price" per line.' => __('No price was found: write one "date,price" per line.', 'ritmo-revenue'),
            default => null,
        };
        if ($known !== null) {
            return $known;
        }
        $room = RoomErrorText::of($message);

        return $room === $message ? null : $room;
    }
}
