<?php

declare(strict_types=1);

namespace Ritmo\Admin\Screens;

use Ritmo\Admin\Components\Labels;
use Ritmo\Admin\Components\Menu;
use Ritmo\Application\Dashboard\Todo\TodoGenerator;
use Ritmo\Application\Dashboard\Todo\TodoItem;

/**
 * The sentences of the to-do items: a person's item shows what they typed; a plugin item is
 * written from its kind and parameters, with why and where to do it.
 */
final class TodoText
{
    private function __construct()
    {
    }

    public static function title(TodoItem $item): string
    {
        $params = $item->params;

        return match ($item->kind) {
            /* translators: %s: room code */
            TodoGenerator::SYNC_NOW => sprintf(__('Press Sync Now in the pricing system for room %s (tonight\'s price changed)', 'ritmo-revenue'), (string) ($params['room'] ?? $item->roomCode)),
            TodoGenerator::CONFIRM_ORIGINS => __('Confirm the list of booking origins', 'ritmo-revenue'),
            TodoGenerator::SAFETY_CONFIRM => __('Confirm the safety limits', 'ritmo-revenue'),
            /* translators: %s: "the PMS" or "the pricing system" */
            TodoGenerator::CONNECTION_TEST => sprintf(__('Test the connection to %s', 'ritmo-revenue'), Labels::provider((string) ($params['provider'] ?? ''))),
            default => $item->title,
        };
    }

    public static function why(TodoItem $item): string
    {
        return match ($item->kind) {
            TodoGenerator::SYNC_NOW => __('The pricing system sends a changed price for tonight only on its next sync; the plugin cannot press the button for you.', 'ritmo-revenue'),
            TodoGenerator::CONFIRM_ORIGINS => __('Until the known origins are confirmed, every booking from a new origin raises a security alert.', 'ritmo-revenue'),
            TodoGenerator::SAFETY_CONFIRM => __('The approval mode needs a person who may change safety limits to confirm them.', 'ritmo-revenue'),
            TodoGenerator::CONNECTION_TEST => __('A connection not tested in the last 7 days, or that failed, stops the approval mode.', 'ritmo-revenue'),
            default => $item->details,
        };
    }

    /**
     * Where to do it: a screen of the plugin, or null when it is done in another system.
     */
    public static function where(TodoItem $item, string $propertyId): ?string
    {
        return match ($item->kind) {
            TodoGenerator::CONFIRM_ORIGINS => Menu::url(Menu::RULES, ['property' => $propertyId]),
            TodoGenerator::SAFETY_CONFIRM => Menu::url(Menu::SETTINGS, ['tab' => 'setup', 'step' => 7, 'property' => $propertyId]),
            TodoGenerator::CONNECTION_TEST => Menu::url(Menu::SETTINGS, ['tab' => 'connections', 'property' => $propertyId]),
            default => null,
        };
    }
}
