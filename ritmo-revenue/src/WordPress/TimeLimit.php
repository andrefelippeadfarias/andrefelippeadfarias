<?php

declare(strict_types=1);

namespace Ritmo\WordPress;

/**
 * The time a request may still work before PHP stops it, for the deadline of a write: before
 * the first call the request asks PHP for the write's time (where the host allows it), and the
 * executor starts no call it could not finish before that limit.
 */
final class TimeLimit
{
    /** Seconds a request that writes asks for. */
    public const WRITE_SECONDS = 300;

    private function __construct()
    {
    }

    /**
     * Before the engine works in this request (a run, a shadow run, a write): asks WordPress for
     * its larger memory limit (WP_MAX_MEMORY_LIMIT, 256M by default, in the admin and in cron), so
     * a run of many rooms and nights does not stop at the 128M of a front-end request.
     */
    public static function forEngine(string $context = 'admin'): void
    {
        if (function_exists('wp_raise_memory_limit')) {
            wp_raise_memory_limit($context);
        }
    }

    /**
     * The seconds a scheduler tick may work: the rule schedule.tick_budget_seconds, asking PHP for
     * that time plus a margin; where the host refuses, kept 5 seconds under the time PHP gives.
     */
    public static function tickBudget(int $rule): int
    {
        $rule = max(5, $rule);
        $limit = (int) ini_get('max_execution_time');
        if ($limit === 0 || $limit >= $rule + 5) {
            return $rule;
        }
        if (function_exists('set_time_limit') && set_time_limit($rule + 30)) {
            return $rule;
        }

        return max(5, min($rule, $limit - 5));
    }

    /**
     * @return int|null seconds left from now; null when PHP sets no limit
     */
    public static function forWrite(): ?int
    {
        self::forEngine();
        $limit = (int) ini_get('max_execution_time');
        if ($limit === 0) {
            return null;
        }
        if ($limit < self::WRITE_SECONDS && function_exists('set_time_limit') && set_time_limit(self::WRITE_SECONDS)) {
            return self::WRITE_SECONDS;
        }
        $started = (float) ($_SERVER['REQUEST_TIME_FLOAT'] ?? microtime(true));

        return max(0, $limit - (int) ceil(microtime(true) - $started));
    }
}
