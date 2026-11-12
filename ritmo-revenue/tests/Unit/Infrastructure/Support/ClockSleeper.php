<?php

declare(strict_types=1);

namespace Ritmo\Tests\Unit\Infrastructure\Support;

use Ritmo\Infrastructure\Support\Sleeper;

/**
 * A sleeper that does not sleep: it moves the test clock forward and remembers each wait.
 */
final class ClockSleeper implements Sleeper
{
    /** @var list<float> */
    public array $sleeps = [];

    public function __construct(private readonly ManualClock $clock)
    {
    }

    public function sleep(float $seconds): void
    {
        $this->sleeps[] = $seconds;
        $this->clock->advance($seconds);
    }
}
