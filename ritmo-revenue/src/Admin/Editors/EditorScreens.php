<?php

declare(strict_types=1);

namespace Ritmo\Admin\Editors;

use Ritmo\Admin\Components\Capability;
use Ritmo\Admin\Components\FormAction;
use Ritmo\Admin\Components\Menu;
use Ritmo\Admin\Components\Screen;

/**
 * The registry of the Rules and Orders screens (spec 05, 4.1): what the integration hands to the
 * menu (screens) and to the form action dispatcher (actions). Assets: assets/editors.css and
 * assets/editors.js on these screens.
 */
final class EditorScreens
{
    public const ASSETS = ['editors'];

    private function __construct()
    {
    }

    /**
     * @return list<Screen>
     */
    public static function screens(): array
    {
        return [
            new Screen(Menu::ORDERS, __('Orders', 'ritmo-revenue'), Capability::VIEW, OrdersScreen::tabs(), OrdersScreen::render(...)),
            new Screen(Menu::RULES, __('Rules', 'ritmo-revenue'), Capability::VIEW, RulesScreen::tabs(), RulesScreen::render(...)),
        ];
    }

    /**
     * @return list<FormAction>
     */
    public static function actions(): array
    {
        return [...RulesActions::actions(), ...OrdersActions::actions()];
    }
}
