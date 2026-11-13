<?php

declare(strict_types=1);

namespace Ritmo\Admin\Screens;

use Ritmo\Admin\Components\Capability;
use Ritmo\Admin\Components\Menu;
use Ritmo\WordPress\Uninstaller;

/**
 * Settings (admin spec 2.1): the tabs setup (the wizard), connections, diagnostics and uninstall
 * of this area; a tab the user may not use answers 403. The notifications tab belongs to the
 * operations screens.
 */
final class SettingsScreen
{
    private function __construct()
    {
    }

    public static function render(): void
    {
        $tabs = array_values(array_filter(Menu::entries(), static fn (array $e): bool => $e['slug'] === Menu::SETTINGS))[0]['tabs'];
        $page = Page::open(Menu::SETTINGS, __('Settings', 'ritmo-revenue'), Capability::VIEW, $tabs);
        match ($page->tab) {
            'setup' => $page->render('settings-setup', WizardView::build($page)),
            'connections' => $page->render('settings-connections', ConnectionsView::build()),
            'uninstall' => $page->render('settings-uninstall', ['delete' => ScreenServices::instance()->services()->store()->get(Uninstaller::DELETE_DATA, false) === true]),
            'notifications' => $page->render('settings-notifications', []),
            default => $page->render('settings-diagnostics', DiagnosticsView::build($page)),
        };
    }
}
