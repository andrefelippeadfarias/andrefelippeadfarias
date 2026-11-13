<?php

declare(strict_types=1);

namespace Ritmo\Application\Schedule;

/**
 * What one scheduler tick did, per property.
 */
final class TickReport
{
    public const IDLE = 'idle';
    public const CONTINUED = 'continued';
    public const STARTED = 'started';
    public const WAITING = 'waiting';
    public const BLOCKED = 'blocked';
    public const DEFERRED = 'deferred';
    public const ERROR = 'error';

    /**
     * @param bool                  $busy       another tick was working: this one did nothing
     * @param array<string, string> $properties property id => one of the constants
     * @param bool                  $failed     the tick itself broke (the database, the lock table): logged, nothing reached WP-Cron
     */
    public function __construct(
        public readonly bool $busy,
        public readonly array $properties = [],
        public readonly bool $failed = false,
    ) {
    }
}
