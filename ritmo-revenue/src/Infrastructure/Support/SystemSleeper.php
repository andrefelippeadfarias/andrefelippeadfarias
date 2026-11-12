<?php

declare(strict_types=1);

namespace Ritmo\Infrastructure\Support;

/**
 * The real wait.
 */
final class SystemSleeper implements Sleeper
{
    public function sleep(float $seconds): void
    {
        if ($seconds <= 0 || !is_finite($seconds)) {
            return;
        }

        usleep((int) ($seconds * 1_000_000));
    }
}
