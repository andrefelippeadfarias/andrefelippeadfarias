<?php

declare(strict_types=1);

namespace Ritmo\Admin\Licence;

use Ritmo\Infrastructure\Update\UpdateHooks;
use Throwable;

/**
 * The WordPress hooks of the licence and the updates: the notice on the plugin's screens, the
 * scheduled licence check (its own WP-Cron event, twice a day; never inside a pricing run, which
 * only reads the stored verdict) and the update filters.
 */
final class LicenceHooks
{
    public const CRON_HOOK = 'ritmo_licence_check';

    private function __construct()
    {
    }

    public static function register(): void
    {
        add_action('admin_notices', [LicenceNotice::class, 'render']);
        add_action(self::CRON_HOOK, [self::class, 'daily']);
        add_action('init', [self::class, 'schedule']);
        UpdateHooks::register(
            static fn () => LicenceServices::instance()->updates(),
            static fn (string $reason): string => LicenceText::updateRefusal($reason),
        );
    }

    /**
     * Keeps the scheduled check on the calendar (twice a day; the check itself asks the provider
     * once a day, LicenceService::daily()).
     */
    public static function schedule(): void
    {
        if (wp_next_scheduled(self::CRON_HOOK) === false) {
            wp_schedule_event(time() + 300, 'twicedaily', self::CRON_HOOK);
        }
    }

    public static function unschedule(): void
    {
        wp_clear_scheduled_hook(self::CRON_HOOK);
    }

    public static function daily(): void
    {
        try {
            LicenceServices::instance()->licence()->daily();
        } catch (Throwable) {
            // daily() logs its own failures; a site that cannot build the services checks tomorrow.
            return;
        }
    }
}
