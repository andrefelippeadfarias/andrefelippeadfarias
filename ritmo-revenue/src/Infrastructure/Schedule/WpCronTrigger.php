<?php

declare(strict_types=1);

namespace Ritmo\Infrastructure\Schedule;

/**
 * The WP-Cron event that drives the scheduler: "ritmo_tick" every five minutes. The plugin's own
 * scheduler (slots, coalescing, missed days, locks) runs inside the tick; WP-Cron only wakes it.
 * On a site without traffic, or with DISABLE_WP_CRON, a system cron must call wp-cron.php (or
 * "wp cron event run ritmo_tick") every five minutes.
 */
final class WpCronTrigger
{
    public const HOOK = 'ritmo_tick';

    public const INTERVAL = 'ritmo_five_minutes';

    public const INTERVAL_SECONDS = 300;

    private function __construct()
    {
    }

    /**
     * For the "cron_schedules" filter: adds the five-minute interval.
     *
     * @param array<string, array{interval: int, display: string}> $schedules
     *
     * @return array<string, array{interval: int, display: string}>
     */
    public static function withInterval(array $schedules): array
    {
        $schedules[self::INTERVAL] = ['interval' => self::INTERVAL_SECONDS, 'display' => 'Every five minutes (Ritmo Revenue)'];

        return $schedules;
    }

    /**
     * Schedules the event once; the first tick comes at $firstAt (a Unix time).
     */
    public static function schedule(int $firstAt): void
    {
        if (wp_next_scheduled(self::HOOK) === false) {
            wp_schedule_event($firstAt, self::INTERVAL, self::HOOK);
        }
    }

    public static function unschedule(): void
    {
        wp_clear_scheduled_hook(self::HOOK);
    }

    /**
     * @return array{disabled: bool, next_tick: int|null}
     */
    public static function state(): array
    {
        $next = wp_next_scheduled(self::HOOK);

        return [
            'disabled' => defined('DISABLE_WP_CRON') && constant('DISABLE_WP_CRON') === true,
            'next_tick' => $next === false ? null : (int) $next,
        ];
    }
}
