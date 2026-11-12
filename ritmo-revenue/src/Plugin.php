<?php

declare(strict_types=1);

namespace Ritmo;

use Ritmo\Admin\AdminActions;
use Ritmo\Admin\AdminPages;
use Ritmo\Rest\RestRoutes;
use Ritmo\WordPress\Activator;
use Ritmo\WordPress\Scheduler;
use Ritmo\WordPress\Services;

/**
 * Entry point of the plugin in WordPress.
 *
 * `boot()` is hooked to `plugins_loaded` by the main plugin file and registers the hooks of the
 * admin screens, the admin actions, the REST API and the scheduled hook. The objects behind them
 * are wired in WordPress\Services. It is safe to call more than once, and outside WordPress it
 * registers nothing.
 */
final class Plugin
{
    public const TEXT_DOMAIN = 'ritmo-revenue';

    private static bool $booted = false;

    public static function boot(): void
    {
        if (self::$booted) {
            return;
        }
        self::$booted = true;
        if (!function_exists('add_action')) {
            return;
        }

        add_action('init', [self::class, 'loadTextDomain']);
        add_action('admin_init', [self::class, 'upgrade']);
        add_action('admin_menu', [AdminPages::class, 'register']);
        add_action('admin_enqueue_scripts', [AdminPages::class, 'enqueue']);
        add_action('admin_notices', [AdminPages::class, 'vaultNotice']);
        add_action('rest_api_init', [RestRoutes::class, 'register']);
        add_action(Scheduler::RESUME_HOOK, [self::class, 'resumeOpenRuns']);
        AdminActions::register();
    }

    public static function isBooted(): bool
    {
        return self::$booted;
    }

    public static function loadTextDomain(): void
    {
        load_plugin_textdomain(self::TEXT_DOMAIN, false, dirname(plugin_basename(RITMO_FILE)) . '/languages');
    }

    /**
     * After the plugin files were updated, brings the tables and roles up to date.
     */
    public static function upgrade(): void
    {
        if (!Services::instance()->migrator()->isUpToDate()) {
            Activator::activate();
        }
    }

    public static function resumeOpenRuns(): void
    {
        Services::instance()->shadowRuns()->resumeOpenRuns();
    }
}
