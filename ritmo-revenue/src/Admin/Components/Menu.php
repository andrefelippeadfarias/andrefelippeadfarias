<?php

declare(strict_types=1);

namespace Ritmo\Admin\Components;

use Closure;

/**
 * The "Ritmo Revenue" menu (admin spec 2.1): twelve entries in a fixed order, their tabs and the
 * capability of each, the counters, and the redirects of the M1-M3 slugs. Each area hands the
 * renderer of its own screens; an entry nobody renders yet shows a short note.
 */
final class Menu
{
    public const TOP = 'ritmo-revenue';
    public const OVERVIEW = 'ritmo-revenue';
    public const NIGHTS = 'ritmo-revenue-nights';
    public const APPROVALS = 'ritmo-revenue-approvals';
    public const ALERTS = 'ritmo-revenue-alerts';
    public const TO_DO = 'ritmo-revenue-todo';
    public const CHANGES = 'ritmo-revenue-changes';
    public const REPORTS = 'ritmo-revenue-reports';
    public const MARKET = 'ritmo-revenue-market';
    public const ORDERS = 'ritmo-revenue-orders';
    public const RULES = 'ritmo-revenue-rules';
    public const OPERATIONS = 'ritmo-revenue-operations';
    public const SETTINGS = 'ritmo-revenue-settings';

    /** Old slug => [new slug, tab]. */
    public const REDIRECTS = [
        'ritmo-revenue-connections' => [self::SETTINGS, 'connections'],
        'ritmo-revenue-setup' => [self::SETTINGS, 'setup'],
        'ritmo-revenue-diagnostics' => [self::SETTINGS, 'diagnostics'],
    ];

    private function __construct()
    {
    }

    /**
     * The twelve entries in their order; titles translated now.
     *
     * @return list<array{slug: string, title: string, capability: string, tabs: list<Tab>}>
     */
    public static function entries(): array
    {
        $v = Capability::VIEW;
        $c = Capability::MANAGE_CONNECTIONS;

        return [
            ['slug' => self::OVERVIEW, 'title' => __('Overview', 'ritmo-revenue'), 'capability' => $v, 'tabs' => []],
            ['slug' => self::NIGHTS, 'title' => __('Nights', 'ritmo-revenue'), 'capability' => $v, 'tabs' => []],
            ['slug' => self::APPROVALS, 'title' => __('Approvals', 'ritmo-revenue'), 'capability' => $v, 'tabs' => [
                new Tab('waiting', __('Waiting', 'ritmo-revenue'), $v),
                new Tab('history', __('History', 'ritmo-revenue'), $v),
            ]],
            ['slug' => self::ALERTS, 'title' => __('Alerts', 'ritmo-revenue'), 'capability' => $v, 'tabs' => []],
            ['slug' => self::TO_DO, 'title' => __('To-do', 'ritmo-revenue'), 'capability' => $v, 'tabs' => []],
            ['slug' => self::CHANGES, 'title' => __('Change log', 'ritmo-revenue'), 'capability' => $v, 'tabs' => []],
            ['slug' => self::REPORTS, 'title' => __('Reports', 'ritmo-revenue'), 'capability' => $v, 'tabs' => []],
            ['slug' => self::MARKET, 'title' => __('Market', 'ritmo-revenue'), 'capability' => $v, 'tabs' => []],
            ['slug' => self::ORDERS, 'title' => __('Orders', 'ritmo-revenue'), 'capability' => $v, 'tabs' => []],
            ['slug' => self::RULES, 'title' => __('Rules', 'ritmo-revenue'), 'capability' => $v, 'tabs' => []],
            ['slug' => self::OPERATIONS, 'title' => __('Operations', 'ritmo-revenue'), 'capability' => $v, 'tabs' => []],
            ['slug' => self::SETTINGS, 'title' => __('Settings', 'ritmo-revenue'), 'capability' => $v, 'tabs' => [
                new Tab('setup', __('Setup', 'ritmo-revenue'), Capability::MANAGE_RULES),
                new Tab('connections', __('Connections', 'ritmo-revenue'), $c),
                new Tab('notifications', __('Notifications', 'ritmo-revenue'), $c),
                new Tab('diagnostics', __('Diagnostics', 'ritmo-revenue'), $v),
                new Tab('uninstall', __('Uninstall', 'ritmo-revenue'), $c),
            ]],
        ];
    }

    /**
     * Adds the menu. $renderers maps a slug to its screen; $counters maps a slug to the number
     * shown next to it (hidden at zero).
     *
     * @param array<string, Closure(): void> $renderers
     * @param array<string, Closure(): int>  $counters
     */
    public static function register(array $renderers, array $counters = []): void
    {
        $title = __('Ritmo Revenue', 'ritmo-revenue');
        $missing = static function (): void {
            echo '<div class="wrap ritmo"><h1>' . esc_html__('Ritmo Revenue', 'ritmo-revenue') . '</h1><p>' . esc_html__('This screen is not available yet.', 'ritmo-revenue') . '</p></div>';
        };
        add_menu_page($title, $title, Capability::VIEW, self::TOP, $renderers[self::OVERVIEW] ?? $missing, 'dashicons-chart-line', 58);
        foreach (self::entries() as $entry) {
            $count = isset($counters[$entry['slug']]) ? ($counters[$entry['slug']])() : 0;
            add_submenu_page(self::TOP, $entry['title'], $entry['title'] . self::counter($count), $entry['capability'], $entry['slug'], $renderers[$entry['slug']] ?? $missing);
        }
        foreach (array_keys(self::REDIRECTS) as $old) {
            $hook = add_submenu_page('', $title, $title, Capability::VIEW, $old, $missing);
            if (is_string($hook)) {
                add_action('load-' . $hook, [self::class, 'redirectOldSlugs']);
            }
        }
    }

    /**
     * The WordPress counter markup, with the text a screen reader says; empty at zero.
     */
    public static function counter(int $count): string
    {
        if ($count <= 0) {
            return '';
        }

        return sprintf(
            ' <span class="awaiting-mod count-%1$d"><span class="pending-count" aria-hidden="true">%2$s</span><span class="screen-reader-text">%3$s</span></span>',
            $count,
            esc_html(number_format_i18n($count)),
            /* translators: %s: how many are waiting */
            esc_html(sprintf(_n('%s waiting', '%s waiting', $count, 'ritmo-revenue'), number_format_i18n($count)))
        );
    }

    /**
     * When an old slug is opened (a hidden page of the menu): its new place, keeping the property.
     */
    public static function redirectOldSlugs(): void
    {
        $query = Input::query();
        $page = $query->text('page', 64);
        if (!isset(self::REDIRECTS[$page])) {
            return;
        }
        [$slug, $tab] = self::REDIRECTS[$page];
        $args = ['page' => $slug, 'tab' => $tab];
        $property = $query->text('property', 32);
        if (preg_match('/^[A-Za-z0-9_-]{1,32}$/', $property) === 1) {
            $args['property'] = $property;
        }
        wp_safe_redirect(add_query_arg($args, admin_url('admin.php')));
        exit;
    }

    /**
     * The URL of a screen of the plugin.
     *
     * @param array<string, string|int> $args
     */
    public static function url(string $slug, array $args = []): string
    {
        return add_query_arg(['page' => $slug] + $args, admin_url('admin.php'));
    }
}
