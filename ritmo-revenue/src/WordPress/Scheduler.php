<?php

declare(strict_types=1);

namespace Ritmo\WordPress;

/**
 * The plugin's WP-Cron event: every hour it resumes the shadow runs that a request left open.
 * Starting runs on a schedule belongs to a later milestone.
 */
final class Scheduler
{
    public const RESUME_HOOK = 'ritmo_resume_shadow_runs';

    private function __construct()
    {
    }

    public static function schedule(): void
    {
        if (wp_next_scheduled(self::RESUME_HOOK) === false) {
            wp_schedule_event(time() + HOUR_IN_SECONDS, 'hourly', self::RESUME_HOOK);
        }
    }

    public static function unschedule(): void
    {
        wp_clear_scheduled_hook(self::RESUME_HOOK);
    }

    /**
     * @return array{disabled: bool, next_run: int|null}
     */
    public static function state(): array
    {
        $next = wp_next_scheduled(self::RESUME_HOOK);

        return [
            'disabled' => defined('DISABLE_WP_CRON') && DISABLE_WP_CRON,
            'next_run' => $next === false ? null : (int) $next,
        ];
    }
}
