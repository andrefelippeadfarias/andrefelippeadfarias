<?php

declare(strict_types=1);

namespace Ritmo\Tests\Fakes;

use Ritmo\Infrastructure\Support\Sleeper;

/**
 * A Sleeper that does not sleep: it moves a MovableClock forward and remembers each wait.
 */
final class ClockSleeper implements Sleeper
{
    /** @var list<float> */
    private array $waits = [];

    public function __construct(private readonly MovableClock $clock)
    {
    }

    public function sleep(float $seconds): void
    {
        $this->waits[] = $seconds;
        $this->clock->advance($seconds);
    }

    /**
     * @return list<float>
     */
    public function waits(): array
    {
        return $this->waits;
    }
}
