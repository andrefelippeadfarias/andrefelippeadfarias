<?php

declare(strict_types=1);

namespace Ritmo;

use ReflectionClass;
use Ritmo\Admin\AdminActions;
use Ritmo\Admin\AdminPages;
use Ritmo\Domain\Alerts\AlertCode;
use Ritmo\Infrastructure\Db\DatabaseException;
use Ritmo\Infrastructure\Db\Repository\AlertRepository;
use Ritmo\Infrastructure\Schedule\WpCronTrigger;
use Ritmo\Rest\RestRoutes;
use Ritmo\WordPress\Activator;
use Ritmo\WordPress\Roles;
use Ritmo\WordPress\Scheduler;
use Ritmo\WordPress\Services;
use Throwable;

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

    private const UPGRADE_LOCK = 'schema_upgrade';

    private const UPGRADE_LOCK_SECONDS = 300;

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
        add_action('init', [self::class, 'upgrade'], 5);
        add_action('admin_menu', [AdminPages::class, 'register']);
        add_action('admin_enqueue_scripts', [AdminPages::class, 'enqueue']);
        add_action('admin_notices', [AdminPages::class, 'vaultNotice']);
        add_action('rest_api_init', [RestRoutes::class, 'register']);
        add_filter('cron_schedules', [WpCronTrigger::class, 'withInterval']);
        add_action(WpCronTrigger::HOOK, [self::class, 'tick']);
        add_action('admin_init', [self::class, 'watchScheduler']);
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
     * After the plugin files were updated (by hand or in the background), brings the tables, the
     * roles, the scheduler event and the stored rules up to date on the first request of any kind
     * (screen, WP-Cron, REST): one option read when nothing is due, and one request at a time.
     */
    public static function upgrade(): void
    {
        $services = Services::instance();
        if ($services->migrator()->isUpToDate()) {
            return;
        }
        try {
            $lock = $services->locks()->acquire(self::UPGRADE_LOCK, self::UPGRADE_LOCK_SECONDS);
        } catch (DatabaseException) {
            Activator::activate(); // a site older than the lock table: the update creates it

            return;
        }
        if ($lock === null) {
            return; // another request is updating
        }
        try {
            Activator::activate();
        } finally {
            $services->locks()->release($lock);
        }
    }

    /**
     * The scheduler tick (WP-Cron, every five minutes, and once more after an approval). It never
     * throws: while the tables are not up to date it does nothing, and a broken tick is logged and
     * raises an alert (a failing WP-Cron event would take the other events of the request down).
     */
    public static function tick(): void
    {
        try {
            $services = Services::instance();
            if (!$services->migrator()->isUpToDate()) {
                $services->logger()->warning('The scheduler tick waits for the database update.');

                return;
            }
            $services->stack()->tick()->run();
        } catch (Throwable $failure) {
            self::reportFailure('tick', $failure);
        }
    }

    /**
     * On the admin screens: the tick's event is put back when it is missing (an update that
     * stopped half way), and a slot left waiting with no tick raises scheduler_stalled. A failure
     * here never takes the admin screens down.
     */
    public static function watchScheduler(): void
    {
        if (!current_user_can(Roles::VIEW)) {
            return;
        }
        try {
            if (Scheduler::state()['next_run'] === null) {
                Scheduler::schedule();
            }
            $stack = Services::instance()->stack();
            $stack->stallMonitor()->check($stack->rules()->schedule()->stalledAfterMinutes);
        } catch (Throwable $failure) {
            self::reportFailure('stall monitor', $failure);
        }
    }

    /**
     * Logs a failure of a background step by its kind only (the message may carry details of the
     * site) and raises scheduler_stalled, so the screens show that the scheduler is not working.
     */
    private static function reportFailure(string $where, Throwable $failure): void
    {
        try {
            $services = Services::instance();
            $services->logger()->error('A background step of the plugin failed.', ['step' => $where, 'error_class' => $failure::class]);
            $services->alerts()->raise(
                AlertCode::SchedulerStalled->value,
                AlertRepository::CRITICAL,
                sprintf('The %s failed (%s): no run starts until it works again.', $where, (new ReflectionClass($failure))->getShortName()),
                ['step' => $where, 'error_class' => $failure::class],
                '',
                '',
                null,
                'scheduler_failed:' . $where,
            );
        } catch (Throwable) {
            // Nothing more can be done from here: the database itself is not reachable.
        }
    }
}
