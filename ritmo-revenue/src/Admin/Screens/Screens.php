<?php

declare(strict_types=1);

namespace Ritmo\Admin\Screens;

use Closure;
use Ritmo\Admin\Components\Capability;
use Ritmo\Admin\Components\FormAction;
use Ritmo\Admin\Components\Input;
use Ritmo\Admin\Components\Menu;
use Throwable;

/**
 * The registry of the central screens (admin spec 4.1): their renderers by menu slug, the menu
 * counters, their form actions and their assets. The integration hands these to the menu and to
 * admin-post.php (Admin\AdminScreens gathers every area).
 */
final class Screens
{
    private function __construct()
    {
    }

    /**
     * @return array<string, Closure(): void>
     */
    public static function renderers(): array
    {
        return [
            Menu::OVERVIEW => OverviewScreen::render(...),
            Menu::NIGHTS => NightsScreen::render(...),
            Menu::APPROVALS => ApprovalsScreen::render(...),
            Menu::ALERTS => AlertsScreen::render(...),
            Menu::TO_DO => TodoScreen::render(...),
            Menu::CHANGES => ChangesScreen::render(...),
            Menu::SETTINGS => SettingsScreen::render(...),
        ];
    }

    /**
     * The counters of the menu: one count each, read once per request; a failure shows none.
     *
     * @return array<string, Closure(): int>
     */
    public static function counters(): array
    {
        $safe = static function (Closure $count): Closure {
            return static function () use ($count): int {
                try {
                    return (int) $count();
                } catch (Throwable) {
                    return 0;
                }
            };
        };
        $services = ScreenServices::instance();

        return [
            Menu::APPROVALS => $safe(static fn (): int => $services->status()->waitingEverywhere()),
            Menu::ALERTS => $safe(static fn (): int => $services->alerts()->countBySeverity(null, ['critical', 'warning'])),
            Menu::TO_DO => $safe(static fn (): int => $services->todoStore()->countDue(null, $services->services()->clock()->today(in_array(wp_timezone_string(), timezone_identifiers_list(), true) ? wp_timezone_string() : 'UTC'))),
        ];
    }

    /**
     * @return list<FormAction>
     */
    public static function actions(): array
    {
        return [
            new FormAction('ritmo_select_property', Capability::VIEW, Menu::OVERVIEW, self::selectProperty(...)),
            ...ApprovalActions::actions(),
            ...AlertActions::actions(),
            ...ChangeActions::actions(),
            ...TodoActions::actions(),
            ...NightActions::actions(),
            ...WizardActions::actions(),
            ...ConnectionActions::actions(),
            ...DiagnosticsActions::actions(),
        ];
    }

    /**
     * On the plugin's screens: the screens' own stylesheet and script (deferred, in the footer).
     */
    public static function enqueue(string $hook): void
    {
        if (!str_contains($hook, Menu::TOP)) {
            return;
        }
        wp_enqueue_style('ritmo-screens', plugins_url('assets/screens.css', RITMO_FILE), ['dashicons'], RITMO_VERSION);
        wp_enqueue_script('ritmo-screens', plugins_url('assets/screens.js', RITMO_FILE), [], RITMO_VERSION, ['in_footer' => true, 'strategy' => 'defer']);
    }

    /**
     * The property picker of the header: remembered per user, then the same screen again.
     *
     * @return array<string, string>
     */
    private static function selectProperty(Input $form): array
    {
        $property = $form->text('property', 32);
        if (ScreenServices::instance()->services()->properties()->find($property) === null) {
            $property = '';
        } else {
            update_user_meta(get_current_user_id(), Page::USER_META, $property);
        }
        $page = $form->text('page', 64);

        return array_filter([
            'page' => in_array($page, array_column(Menu::entries(), 'slug'), true) ? $page : Menu::OVERVIEW,
            'property' => $property,
            'tab' => preg_match('/^[a-z_]{1,32}$/', $form->text('tab', 32)) === 1 ? $form->text('tab', 32) : '',
        ], static fn (string $v): bool => $v !== '');
    }
}
