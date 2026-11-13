<?php

declare(strict_types=1);

namespace Ritmo\Application\Schedule;

/**
 * What the launcher did with a run.
 */
final class LaunchResult
{
    public function __construct(
        public readonly LaunchStatus $status,
        public readonly ?int $runId = null,
        public readonly ?FailureKind $failure = null,
        public readonly string $note = '',
    ) {
    }

    public static function busy(): self
    {
        return new self(LaunchStatus::Busy, null, null, 'Another run of the property is in progress.');
    }
}
