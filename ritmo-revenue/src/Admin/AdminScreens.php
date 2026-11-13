<?php

declare(strict_types=1);

namespace Ritmo\Admin;

use Closure;
use Ritmo\Admin\Components\FormAction;
use Ritmo\Admin\Components\Menu;
use Ritmo\Admin\Components\Screen;
use Ritmo\Admin\Editors\EditorScreens;
use Ritmo\Admin\Market\MarketActions;
use Ritmo\Admin\Market\MarketScreen;
use Ritmo\Admin\Reports\ReportsActions;
use Ritmo\Admin\Reports\ReportsScreen;
use Ritmo\Admin\Screens\OperationsActions;
use Ritmo\Admin\Screens\OperationsScreen;
use Ritmo\Admin\Screens\ScreenActions;
use Ritmo\Admin\Screens\Screens;
use Ritmo\Admin\Screens\SettingsActions;
use Ritmo\WordPress\Roles;
use Ritmo\WordPress\Services;

/**
 * The admin of the plugin in WordPress (admin spec 2, 4.1): the twelve entries of the menu from
 * every area's screens, the counters, the redirects of the M1-M3 slugs, the stylesheets and
 * scripts of each screen, every form action of admin-post.php (POST only, capability, nonce,
 * then the handler: Screens\ScreenActions) and the notice of a vault that cannot work.
 */
final class AdminScreens
{
    /** Screens whose templates use the editors' frame and stylesheet. */
    private const EDITOR_SCREENS = [Menu::ORDERS, Menu::RULES, Menu::MARKET, Menu::REPORTS];

    private function __construct()
    {
    }

    public static function register(): void
    {
        Menu::register(self::renderers(), Screens::counters());
    }

    /**
     * Every screen's renderer by menu slug.
     *
     * @return array<string, Closure(): void>
     */
    public static function renderers(): array
    {
        $renderers = Screens::renderers() + [Menu::OPERATIONS => OperationsScreen::render(...)];
        foreach ([...EditorScreens::screens(), ...MarketScreen::screens(), ...ReportsScreen::screens()] as $screen) {
            /** @var Screen $screen */
            $renderers[$screen->slug] = $screen->render;
        }

        return $renderers;
    }

    /**
     * Every form action of the plugin; each name once.
     *
     * @return list<FormAction>
     */
    public static function actions(): array
    {
        return [
            ...Screens::actions(),
            ...OperationsActions::actions(),
            ...SettingsActions::actions(),
            ...EditorScreens::actions(),
            ...MarketActions::actions(),
            ...ReportsActions::actions(),
        ];
    }

    public static function registerActions(): void
    {
        ScreenActions::register(self::actions());
    }

    /**
     * On the plugin's screens only: the shared stylesheet and script, then the editors' and the
     * reports' own (deferred, in the footer). Nothing comes from outside the plugin and WordPress.
     */
    public static function enqueue(string $hook): void
    {
        if (!str_contains($hook, Menu::TOP)) {
            return;
        }
        Screens::enqueue($hook);
        $page = self::page($hook);
        if (in_array($page, self::EDITOR_SCREENS, true)) {
            wp_enqueue_style('ritmo-editors', plugins_url('assets/editors.css', RITMO_FILE), ['ritmo-screens'], RITMO_VERSION);
            wp_enqueue_script('ritmo-editors', plugins_url('assets/editors.js', RITMO_FILE), [], RITMO_VERSION, ['in_footer' => true, 'strategy' => 'defer']);
        }
        if ($page === Menu::REPORTS) {
            wp_enqueue_style('ritmo-reports', plugins_url('assets/reports.css', RITMO_FILE), ['ritmo-editors'], RITMO_VERSION);
            wp_enqueue_script('ritmo-reports', plugins_url('assets/reports.js', RITMO_FILE), [], RITMO_VERSION, ['in_footer' => true, 'strategy' => 'defer']);
        }
    }

    /**
     * On every admin screen, to whoever manages the connections: a RITMO_SECRET_KEY the vault
     * cannot use. The plugin keeps working; saved keys wait until the constant is fixed.
     */
    public static function vaultNotice(): void
    {
        $problem = current_user_can(Roles::MANAGE_CONNECTIONS) ? Services::instance()->vault()->problem() : null;
        if ($problem === null) {
            return;
        }
        printf(
            '<div class="notice notice-error" data-ritmo="vault-problem"><p><strong>%s</strong> %s</p></div>',
            esc_html__('Ritmo Revenue:', 'ritmo-revenue'),
            esc_html__('RITMO_SECRET_KEY in wp-config.php is too short (at least 32 characters). Saved keys cannot be used or changed until it is fixed.', 'ritmo-revenue')
        );
    }

    /**
     * The menu slug of a screen's hook suffix ("ritmo-revenue_page_ritmo-revenue-rules").
     */
    private static function page(string $hook): string
    {
        foreach (array_column(Menu::entries(), 'slug') as $slug) {
            if ($hook === 'toplevel_page_' . $slug || str_ends_with($hook, '_page_' . $slug)) {
                return $slug;
            }
        }

        return '';
    }
}
