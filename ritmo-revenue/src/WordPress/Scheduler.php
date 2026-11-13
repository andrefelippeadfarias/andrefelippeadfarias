<?php

declare(strict_types=1);

namespace Ritmo\WordPress;

use Ritmo\Infrastructure\Schedule\WpCronTrigger;

/**
 * The plugin's WP-Cron event (operations spec 3): every five minutes the scheduler tick starts
 * the due runs of every property, continues the runs in progress and writes approved plans.
 * Registered at activation, removed at deactivation and uninstall. The hourly event of earlier
 * versions (it only resumed shadow runs) is removed on the way.
 */
final class Scheduler
{
    /** The hourly event of milestones 1 and 2, cleared on activation. */
    public const LEGACY_HOOK = 'ritmo_resume_shadow_runs';

    /** The one-off tick an approval asks for (WpCronTrigger::HOOK with this argument). */
    public const NOW = 'now';

    private function __construct()
    {
    }

    /**
     * At activation the plugin was loaded after "plugins_loaded", so the five-minute interval is
     * added here as well before the event that uses it.
     */
    public static function schedule(): void
    {
        add_filter('cron_schedules', [WpCronTrigger::class, 'withInterval']);
        wp_clear_scheduled_hook(self::LEGACY_HOOK);
        WpCronTrigger::schedule(time() + MINUTE_IN_SECONDS);
    }

    public static function unschedule(): void
    {
        wp_clear_scheduled_hook(self::LEGACY_HOOK);
        wp_clear_scheduled_hook(WpCronTrigger::HOOK, [self::NOW]);
        WpCronTrigger::unschedule();
    }

    /**
     * After an approval: a tick now, in a background request, so the approved write starts at
     * once (operations spec D16); it still waits for the property's run lock.
     */
    public static function tickNow(): void
    {
        wp_schedule_single_event(time() - 1, WpCronTrigger::HOOK, [self::NOW]);
        WpCronTrigger::spawnNow();
    }

    /**
     * @return array{disabled: bool, next_run: int|null}
     */
    public static function state(): array
    {
        $state = WpCronTrigger::state();

        return ['disabled' => $state['disabled'], 'next_run' => $state['next_tick']];
    }
}
