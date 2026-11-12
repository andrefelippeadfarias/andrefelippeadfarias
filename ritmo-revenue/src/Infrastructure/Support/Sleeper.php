<?php

declare(strict_types=1);

namespace Ritmo\Infrastructure\Support;

/**
 * Waiting, as a dependency: rate limiters and credit waits never call sleep() themselves, so
 * tests replace the wait with a clock advance and nothing really sleeps.
 */
interface Sleeper
{
    /**
     * Blocks for the given number of seconds (fractions allowed). Zero or less returns at once.
     */
    public function sleep(float $seconds): void;
}
