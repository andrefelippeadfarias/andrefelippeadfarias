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
     * @return int|null seconds left from now; null when PHP sets no limit
     */
    public static function forWrite(): ?int
    {
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
